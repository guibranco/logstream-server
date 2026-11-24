# ClickHouse log store

> **Status:** usable for writing and searching. Still to come
> ([#154](https://github.com/guibranco/logstream-server/issues/154)): the
> non-blocking batch writer (#157; buffered ingest uses blocking HTTP inserts
> meanwhile), retention policies (#158; the table's 35-day TTL applies
> meanwhile, and `bin/retention.php` / `bin/prune.php` exit with a message), and
> the MariaDB → ClickHouse copy (#159).

ClickHouse holds **log entries only**. Clients, retention policies and the
retention log stay in the control-plane database (`STORAGE_TYPE`). See
[Storage: control plane and log store](../README.md#storage-control-plane-and-log-store).

Pinned version: **ClickHouse 26.8 LTS** (supported until August 2027). The same
tag is used by `docker-compose.yml` and CI.

---

## Using it

```bash
LOG_STORE=clickhouse
CLICKHOUSE_URL=http://127.0.0.1:8123   # HTTP interface, no path
CLICKHOUSE_DATABASE=logstream
CLICKHOUSE_USER=logstream
CLICKHOUSE_PASSWORD=…
CLICKHOUSE_TIMEOUT=10                  # seconds, to connect and per answer
```

The server pings ClickHouse at startup and exits if it can't reach it (like
MariaDB). Afterwards, `GET /api/health` includes a `log_store` block and reports
`"status": "degraded"` while ClickHouse is unreachable, still with HTTP 200.

The server talks to the HTTP interface with PHP's own HTTP streams (no extra
extension). Every search value is sent as a server-side query parameter, never
inside the SQL. Searches answer exactly as MariaDB does; the same contract tests
run on file, MariaDB and ClickHouse:

| Filter | ClickHouse |
|---|---|
| `app_key`, `level`, `trace_id`, … | `=` / `IN`; an unknown `level` matches nothing |
| `search`, `category`, `user_agent` | case-insensitive "contains" (`lower(…) LIKE` for ASCII, so the n-gram index can help) |
| `context[a.b]=v` | the value at that path, unquoted, equals `v` (`42`, `true`, `null` as written); `context_not` also keeps entries without the path |
| `context_search` | whole words, like MariaDB's FULLTEXT (`card` doesn't match `card_declined`) |
| `date_from`, `date_to` | inclusive, UTC |

Reads use `FINAL`, so an entry written twice by a retried batch is returned once.
Writes use ClickHouse's asynchronous inserts, so many small inserts (sync ingest)
don't create a part each.

## Schema

`migrations/clickhouse/001_log_entries.sql` creates one table, `log_entries`:

| Choice | Why |
|---|---|
| `ReplacingMergeTree(created_at)`, `ORDER BY (app_key, timestamp, id)` | ClickHouse has no transactions, so a retried batch can be inserted twice. Rows with the same key are merged into one; queries that must be exact can add `FINAL`. |
| `PARTITION BY toYYYYMMDD(timestamp)` | "Older than N days" retention becomes `DROP PARTITION`, which is instant. |
| `TTL … + INTERVAL 35 DAY` with `ttl_only_drop_parts` | Backstop only. Retention policies normally delete much earlier (1 month at most). |
| `LowCardinality` for `app_key`, `app_id`, `category`, `transport`; `Enum8` for `level` | Few distinct values: smaller on disk and fast to filter. |
| `context` as a JSON string with `ZSTD(3)` | Read with `JSONExtract*` at query time. The native `JSON` type can be revisited later. |
| Bloom filters on `id`, `trace_id`, `batch_id`, `message_template` | Point lookups (`GET /api/logs/:id` accepts either id) and exact filters. |
| `ngrambf_v1` on `lower(message)` | `search=` is a case-insensitive substring match. |
| `tokenbf_v1` on `lower(context)` | `context_search=` matches whole words, like MariaDB's FULLTEXT index. |

`tests/Unit/Storage/ClickHouseSchemaTest.php` fails if `LogEntry` gains a field
the schema doesn't have.

## Applying migrations

`Tools/clickhouse-migration.sh` applies `migrations/clickhouse/*.sql` in order
over the HTTP interface (only `curl` is needed) and records each file in a
`schema_versions` table **in the ClickHouse database**, so re-runs are no-ops.

```bash
export CLICKHOUSE_URL=http://127.0.0.1:8123   # default
export CLICKHOUSE_DATABASE=logstream          # must already exist
export CLICKHOUSE_USER=logstream
export CLICKHOUSE_PASSWORD=…

bash Tools/clickhouse-migration.sh --dry-run  # list pending files
bash Tools/clickhouse-migration.sh            # apply them
```

The password is passed to curl through a temporary config file, never on the
command line. In GitHub Actions the script sets the same `files`, `error` and
`error_message` outputs as `Tools/db-migration.sh`.

**Writing a migration.** ClickHouse can't roll back, so a file that fails halfway
is re-run from the top next time:

- end every statement with `;` at the end of a line (that's how the file is split);
- make every statement safe to run twice (`CREATE TABLE IF NOT EXISTS`,
  `ADD COLUMN IF NOT EXISTS`, `ADD INDEX IF NOT EXISTS`, …);
- lines starting with `--` are dropped.

The MariaDB runner only reads files directly in `migrations/`, and MariaDB's
`docker-entrypoint-initdb.d` ignores subfolders, so the `clickhouse/` folder
never reaches MariaDB.

## Local development

```bash
docker compose --profile clickhouse up -d clickhouse

export CLICKHOUSE_URL=http://127.0.0.1:8123 CLICKHOUSE_USER=logstream CLICKHOUSE_PASSWORD=test
bash Tools/clickhouse-migration.sh
bash tests/clickhouse/schema-check.sh   # writes test rows, then truncates log_entries
```

The service listens on `127.0.0.1:8123` only. It isn't started without the
`clickhouse` profile, so `docker compose up` behaves as before.

## Host configuration (6 GB host)

Sized for vinhedo5 (Ampere A1, 1 OCPU / 6 GB), shared with LogStream and NGINX:

| Consumer | Budget |
|---|---|
| ClickHouse (`max_server_memory_usage`) | 3 GiB |
| LogStream (ingest buffer capped at 50k entries) | ~100–300 MB |
| OS, page cache, backups | the rest (~2.5 GB) |

The two config files in `docker/clickhouse/` are the ones to install:

```bash
sudo cp docker/clickhouse/config.d/logstream.xml /etc/clickhouse-server/config.d/
sudo cp docker/clickhouse/users.d/logstream.xml  /etc/clickhouse-server/users.d/
sudo systemctl restart clickhouse-server
```

`config.d/logstream.xml` (server-wide):

| Setting | Value | Default |
|---|---|---|
| `max_server_memory_usage` | 3 GiB | 90% of RAM |
| `mark_cache_size` | 256 MiB | 5 GiB |
| `index_mark_cache_size` | 64 MiB | — |
| `uncompressed_cache_size` | 128 MiB | — |
| `background_pool_size` | 2 | 16 |
| `merge_tree` free-slot thresholds (`…_lower_max_size_of_merge`, `…_execute_mutation`, `…_execute_optimize_entire_partition`) | 2 | 8, 20, 25 |
| `max_concurrent_queries` | 20 | — |
| `metric_log`, `asynchronous_metric_log`, `trace_log` | removed | enabled |
| `query_log` | kept 7 days | kept forever |

The `merge_tree` thresholds must fit the smaller merge pool (2 threads × a
concurrency ratio of 2 = 4 slots). With the defaults, ClickHouse refuses to load
MergeTree tables and doesn't start.

`users.d/logstream.xml` (default profile): `max_threads` 2 and `max_memory_usage`
1 GiB per query.

One A1 OCPU is a single core, so give the ReactPHP event loop priority over
merges with systemd drop-ins:

```bash
sudo systemctl edit clickhouse-server   # [Service] CPUWeight=50
sudo systemctl edit logstream-server    # [Service] CPUWeight=200
```

Listen on loopback only (`<listen_host>127.0.0.1</listen_host>` in a
`config.d` file), and don't open 8123 or 9000 in UFW or the OCI security list:
LogStream runs on the same host.

### Database and user

```sql
CREATE DATABASE IF NOT EXISTS logstream;
CREATE USER IF NOT EXISTS logstream IDENTIFIED WITH sha256_password BY '…' HOST LOCAL;
GRANT SELECT, INSERT, ALTER, CREATE TABLE, DROP TABLE, TRUNCATE, OPTIMIZE ON logstream.* TO logstream;
```

Keep the password in Grimoire. `ALTER` covers `DROP PARTITION` and lightweight
`DELETE` for retention; `CREATE TABLE` is needed by the migration runner.

Provisioning and deployment on vinhedo5 are tracked in
[#160](https://github.com/guibranco/logstream-server/issues/160).
