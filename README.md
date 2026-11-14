# logstream-server
📡🪵 Real-time log ingestion and streaming service built with PHP 8.3 + ReactPHP + Ratchet

---

## Architecture

```
[ Your apps ]  ──POST /api/logs──▶  [ HTTP server :8081 ]
                                            │
                                       saves to storage
                                       (MariaDB / JSONL)
                                            │
                                       broadcasts via
                                            ▼
                                  [ WS server :8080 ]  ──▶  [ React UI ]
```

---

## Quick start

```bash
# 1. Install dependencies
composer install

# 2. Configure
cp .env.example .env
# Edit .env – set API_SECRET, choose STORAGE_TYPE, etc.

# 3a. MariaDB (optional)
mysql -u root -p < migrations/001_logs.sql

# 3b. File storage – nothing to do; directories are auto-created.

# 4. Run
php bin/server.php
```

---

## API Reference

All requests to write endpoints must include:
```
Authorization: Bearer <API_SECRET>
```

### POST /api/logs — Ingest logs

**Batch payload (recommended):**
```json
{
  "app_key":  "billing-api",
  "app_id":   "production",
  "batch_id": "550e8400-e29b-41d4-a716-446655440000",
  "logs": [
    {
      "trace_id":  "6ba7b810-9dad-11d1-80b4-00c04fd430c8",
      "level":     "error",
      "category":  "payments",
      "message":   "Charge failed for invoice #1234",
      "context":   { "invoice_id": 1234, "code": "card_declined" },
      "timestamp": "2025-01-15T14:22:00.000Z"
    }
  ]
}
```

`User-Agent` header is captured as the application identifier:
```
User-Agent: BillingService/2.1.0 (PHP 8.3)
```

**Single-entry shorthand:**
```json
{
  "app_key":  "billing-api",
  "app_id":   "production",
  "level":    "info",
  "category": "startup",
  "message":  "Service started"
}
```

**Message templates.** A message can be a template, as in Seq
([message templates](https://messagetemplates.org/)):
placeholders name `context` keys and are replaced when the entry is stored.

```json
{
  "level":   "info",
  "message": "GitHub {event} ({action}) for {repository.full_name}",
  "context": { "event": "push", "action": null, "repository": { "full_name": "guibranco/webhooks" } }
}
```

is stored with `message` set to `GitHub push (-) for guibranco/webhooks` and
`message_template` set to the original text, so all entries from that line can
be found with `?message_template=` whatever their values.

- Dotted names reach nested objects; a key that itself contains a dot wins.
- `null` renders as `-`, booleans as `true`/`false`, arrays and objects as JSON.
- A placeholder the context does not resolve is left as written, so messages
  that contain braces for other reasons are stored unchanged, without a template.
- A client that renders messages itself can send both `message` and
  `message_template`; both are stored as sent. A `message_template` without a
  `message` is rendered by the server.

**Response (201):**
```json
{
  "saved":   1,
  "entries": [ { ...LogEntry } ],
  "errors":  null
}
```

---

### GET /api/logs — Search

| Param        | Type   | Description                                |
|--------------|--------|--------------------------------------------|
| `app_key`    | string | Exact match                                |
| `app_id`     | string | Exact match                                |
| `user_agent` | string | Partial match                              |
| `level`      | string | `debug\|info\|notice\|warning\|error\|critical` |
| `category`   | string | Partial match                              |
| `trace_id`   | string | Exact match                                |
| `batch_id`   | string | Exact match                                |
| `sender_ip`  | string | Exact match                                |
| `date_from`  | string | ISO 8601 timestamp (inclusive)             |
| `date_to`    | string | ISO 8601 timestamp (inclusive)             |
| `range`      | string | Relative range ending now, replaces `date_from`/`date_to`: `<n>m`, `<n>h`, `<n>d`, `<n>w` (e.g. `15m`, `60d`, `90d`, `365d`), `week` (since Sunday 00:00), `month` (since the 1st, 00:00) or `now` (only new entries) |
| `tz`         | string | IANA time zone for `range=week`/`month` (default UTC) |
| `search`     | string | Substring match on `message`               |
| `message_template` | string | Exact match on the message template  |
| `context[<path>]`     | string | `context` property equals value (see below) |
| `context_not[<path>]` | string | `context` property differs from value or is absent |
| `context_search` | string | Free text across all `context` keys and values (3–200 chars) |
| `limit`      | int    | Max entries returned (default 100, max 1000) |
| `offset`     | int    | Pagination offset (default 0)              |

**Context filters.** `<path>` is a property name in `context`; use dots for
nested objects (`context[order.id]=42` matches `{"order": {"id": 42}}`).
Values are compared as case-sensitive strings: numbers in plain form, booleans
as `true`/`false`, JSON null as `null`. Repeat the parameter to combine
conditions (AND); at most 10 per request. A malformed key returns `400`.

**Context search.** `context_search=card_declined` finds the term anywhere in
`context`, ignoring case; multi-word terms match as a phrase. In MariaDB it uses
a FULLTEXT index (apply `migrations/006_context_fulltext.sql`) and matches
**whole words** — `card` does not find `card_declined`. File storage decodes
every entry during the scan and matches **substrings**; it works, but MariaDB
is recommended for context-heavy workloads.

**Response (200):**
```json
{
  "total":   42,
  "limit":   100,
  "offset":  0,
  "entries": [ { ...LogEntry } ]
}
```

---

### GET /api/logs/:id

Fetch a single entry by internal `id` or `trace_id`.

---

### GET /api/health

```json
{ "status": "ok", "time": "...", "ws_connections": 2 }
```

---

## WebSocket – ws://host:8080

Connect from the UI to receive a live stream of every ingested log.

**On connect the server sends:**
```json
{ "type": "connected", "connections": 1 }
```

**Every new log entry is broadcast as:**
```json
{
  "type": "log",
  "data": {
    "id":         "01HZXYZ...",
    "trace_id":   "uuid-v4",
    "batch_id":   "uuid-v4 | null",
    "app_key":    "billing-api",
    "app_id":     "production",
    "user_agent": "BillingService/2.1.0",
    "level":      "error",
    "category":   "payments",
    "message":    "Charge failed",
    "context":    { ... },
    "timestamp":  "2025-01-15T14:22:00.000Z",
    "created_at": "2025-01-15T14:22:00.012Z",
    "sender_ip":  "203.0.113.7"
  }
}
```

**Client → server messages:**
```json
{ "type": "ping" }   →   { "type": "pong" }
{ "type": "stats" }  →   { "type": "stats", "data": { "connections": 2 } }
```

---

## LogEntry fields

| Field        | Type            | Notes                                  |
|--------------|-----------------|----------------------------------------|
| `id`         | string (ULID)   | Time-sortable, generated by server     |
| `trace_id`   | string (UUID)   | Provide your own or let server generate |
| `batch_id`   | string (UUID)?  | Groups entries in the same request     |
| `app_key`    | string ≤100     | **Required.** Application slug         |
| `app_id`     | string ≤100     | **Required.** Instance / environment   |
| `user_agent` | string ≤255?    | Application name & version             |
| `level`      | enum            | debug / info / notice / warning / error / critical |
| `category`   | string ≤100     | Free-form grouping tag                 |
| `message`    | string          | Human-readable description (rendered when sent as a template) |
| `message_template` | string? | Template the message was rendered from (see Message templates) |
| `context`    | object?         | Arbitrary structured data              |
| `timestamp`  | ISO 8601        | When the event occurred                |
| `created_at` | ISO 8601        | When the server persisted it           |
| `sender_ip`  | string?         | Client IP, captured by the server (see below) |

### Sender IP

Every ingested entry records the IP address of the client that sent it as
`sender_ip`. It comes from the connection and cannot be set in the request body.

Behind a reverse proxy the connection comes from the proxy, so set
`TRUSTED_PROXIES` to the proxy addresses (comma-separated IPs or CIDR ranges).
For requests from a trusted proxy, the server reads the original client from
`X-Forwarded-For`, skipping trusted hops from the right, and falls back to
`X-Real-IP`. Forwarding headers from any other peer are ignored.

```dotenv
TRUSTED_PROXIES=127.0.0.1,::1            # Nginx on the same host
TRUSTED_PROXIES=172.16.0.0/12            # Nginx container on a Docker network
```

In MariaDB mode, apply `migrations/005_sender_ip.sql` to add the column.
Entries stored before that keep `sender_ip: null`.

### Message template storage

In MariaDB mode, apply `migrations/007_message_template.sql` to add the
`message_template` column. Entries stored before that, and messages without
resolvable placeholders, keep `message_template: null`.

Retention policies match `message_regex` and `message_glob` against both the
rendered message and the template. A policy can therefore target a template
(`"message_glob": "GitHub {event}*"`), which stays the same whatever the values.
A policy written for an old fixed message stops matching once the client
changes that message, so review policies when messages change.

### Retention runs

In MariaDB mode, retention policies live in the `retention_policies` table
(managed from the UI or `/api/retention/policies`). The server's scheduled run,
the API, `bin/retention.php` and `bin/prune.php` all read them from there; the
JSON file named by `RETENTION_CONFIG` only supplies `enabled` and
`interval_hours`. In file mode the policies come from that JSON file.

Every run (real or `--dry-run` / `?dry_run=1`) is written to `retention_log`
in MariaDB mode, one row per policy, including the error when a policy fails.
A failed policy reports `pruned: 0`, so check `error` in the result (the API
response also returns a `failed` count, and the CLI scripts exit with code 1).

```sql
SELECT ran_at, policy_name, dry_run, deleted, error
FROM retention_log ORDER BY id DESC LIMIT 20;
```

### Retention volume chart

`GET /api/retention/volume?days=90` returns stored entries per day (count and
size), how many each policy matches at any age, and how many are already past
a policy's cutoff (`due_*`, counted once when policies overlap). The UI draws
it like Seq's storage view: volume by age with a numbered marker at each
policy's `older_than_days`.

Sizes are exact JSONL line sizes in file mode and the length of message,
template and context in MariaDB mode. In MariaDB mode the report also has a
`storage` object with the table's real on-disk size split into data and
indexes, read from `information_schema.TABLES` (`DATA_LENGTH`, `INDEX_LENGTH`,
`DATA_FREE`, `TABLE_ROWS`). Those are InnoDB statistics for the whole table,
so they are estimates and not split by day; run `ANALYZE TABLE log_entries`
to refresh them. `free_bytes` (`DATA_FREE`) is kept out of `total_bytes`: with
a shared tablespace it is the whole tablespace's free space, not this table's.
It is `null` in file mode, or when `information_schema` cannot be read (logged
as `[Retention] Could not read table size…`). The report reads every stored entry, so
the server caches it for up to 5 minutes; editing a policy, or a run that
deletes entries, refreshes it.

### Testing

```bash
composer test:unit                 # no external services
composer test:integration          # file storage always; MariaDB when configured
```

The MariaDB integration tests run only when `TEST_DB_DSN` is set, and they
**wipe and rebuild that database from `migrations/`**, so point them at a
throwaway one:

```bash
docker run -d --rm --name lsdb -p 3307:3306 -e MARIADB_ROOT_PASSWORD=x -e MARIADB_DATABASE=logstream_test mariadb:11
TEST_DB_DSN="mysql:host=127.0.0.1;port=3307;dbname=logstream_test;charset=utf8mb4" \
TEST_DB_USER=root TEST_DB_PASS=x composer test:integration
```
