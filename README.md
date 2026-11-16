# logstream-server
📡🪵 Real-time log ingestion and streaming service built with PHP 8.3 + ReactPHP + Ratchet

---

## Live demo

A public demo runs on Render's free plan, with file storage:

| | |
|---|---|
| **Server** | <https://logstream-demo.onrender.com> (API docs at the root, health at `/api/health`) |
| **Demo traffic** | <https://logstream-demo.onrender.com/demo>: run fake scenarios, backfill history |
| **UI** | [Open the demo, already connected](https://guilherme.stracini.com.br/logstream-ui/#server=https%3A%2F%2Flogstream-demo.onrender.com&secret=lsu_Cj4qmrHvWkH3BfMyUn4HELyBsldiprEGZfB0VAOR2S0) |
| **`API_SECRET`** (write) | `lsa_fFWu1hWJ19kr_1g9maLswqk4tNCh-24qhv8s2eSMbVs` |
| **`UI_SECRET`** (UI key: read + admin) | `lsu_Cj4qmrHvWkH3BfMyUn4HELyBsldiprEGZfB0VAOR2S0` |

Send a log entry:

```bash
curl -X POST https://logstream-demo.onrender.com/api/logs \
  -H "Authorization: Bearer lsa_fFWu1hWJ19kr_1g9maLswqk4tNCh-24qhv8s2eSMbVs" \
  -H "Content-Type: application/json" \
  -d '{"app_key":"demo","app_id":"readme","level":"info","category":"test","message":"Hello from the README"}'
```

Then open the UI with the [demo link](https://guilherme.stracini.com.br/logstream-ui/#server=https%3A%2F%2Flogstream-demo.onrender.com&secret=lsu_Cj4qmrHvWkH3BfMyUn4HELyBsldiprEGZfB0VAOR2S0): the settings screen opens pre-filled with the server and
the `UI_SECRET`, so just click **Validate and Connect**. To set it up by hand instead, open
<https://guilherme.stracini.com.br/logstream-ui/> and, in **Settings**, use `https://logstream-demo.onrender.com`
as the API URL, `wss://logstream-demo.onrender.com/ws` as the WebSocket URL and the `UI_SECRET` above.

The demo fills itself with fake traffic from *ShopFast*, a made-up shop of six services, every time
it starts, so the UI has events, traces and every log level to explore right away. To send more, open
**<https://logstream-demo.onrender.com/demo>** and pick a scenario (a checkout, a declined card, a
gateway timeout…). Both only exist with `DEMO_MODE=true`; see [demo data](docs/deploy-render.md#demo-data).

![The /demo page: scenario buttons, backfill and continuous mode, and the activity list](docs/assets/images/demo-traffic.png)

> ⚠️ **Demo only.** The service spins down after 15 minutes without traffic, and the first request after
> that takes about a minute to wake it. Storage is not persistent: logs are lost on every redeploy, restart
> or spin-down, and the demo retention deletes entries older than 1 day. The keys are public, and the `UI_SECRET` is not read-only: it
> also manages retention, so anyone can edit the demo's policies or delete its logs — **don't send real or personal data**. How it is deployed: [`docs/deploy-render.md`](docs/deploy-render.md).

---

## Architecture

```
[ Your apps ]  ──POST /api/logs──▶  [ HTTP server :8081/tcp ] ─┐
[ Your apps ]  ──signed packets──▶  [ UDP ingest  :8082/udp ] ─┤
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

**Bare array (log shippers):** Fluent Bit, Vector and similar tools send a plain JSON array. It is accepted as a batch; since there is no top level, pass `app_id` (and, in file mode, `app_key`) as the `X-App-Id` / `X-App-Key` headers:
```
X-App-Id: vinhedo-3

[
  { "level": "error",   "category": "nginx-access", "message": "GET /api/repos 502" },
  { "level": "warning", "category": "nginx-access", "message": "GET /favicon.ico 404" }
]
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

**Response — buffered mode (default), 202:**
```json
{
  "accepted": 1,
  "entries":  [ { "id": "01HZ3XKPQ7V8WBNM0S4DECJRA", "trace_id": "6ba7b810-…" } ],
  "errors":   null
}
```

**Response — `Prefer: return=representation`, or sync mode, 201:**
```json
{
  "saved":   1,
  "entries": [ { ...LogEntry } ],
  "errors":  null
}
```

See [Ingest modes](#ingest-modes) for when each is returned.

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
| `transport`  | string | `tcp\|udp` (exact match, repeatable)       |
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
{
  "status": "ok", "time": "...", "ws_connections": 2,
  "ingest": { "mode": "buffered", "db_driver": "async", "writer": "async-mysql",
              "queued": 0, "in_flight": 0, "persisted_total": 1532,
              "consecutive_failures": 0, "last_error": null, "...": "..." }
}
```

`ingest` reports the buffer state (see [Ingest modes](#ingest-modes)); in sync
mode it is just `{ "mode": "sync" }`.

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
| `transport`  | `tcp\|udp`?     | How the entry arrived: `tcp` (HTTP) or `udp`, set by the server |

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

### Transport

Every ingested entry also records how it reached the server as `transport`:
`tcp` for HTTP ingestion (`POST /api/logs`, including the demo page) and `udp`
for the UDP listener. Like `sender_ip` it is set by the server and a
`transport` in the payload is ignored. The server's own logs
(`logstream-server`) are generated in-process and keep `transport: null`.

In MariaDB mode, apply `migrations/009_transport.sql` to add the column.
Entries stored before that keep `transport: null`.

### Ingest modes

`POST /api/logs` can answer before or after the entries are written.

| `INGEST_MODE`        | Response | Writes |
|----------------------|----------|--------|
| `buffered` (default) | **202 Accepted** with each entry's `id` and `trace_id` | Queued in memory, written in batches every `INGEST_FLUSH_INTERVAL_MS` (500 ms) or as soon as `INGEST_BATCH_SIZE` (500) entries wait |
| `sync`               | **201 Created** with the stored entries | One write per entry before responding |

In buffered mode a client that needs to know its entries are stored sends
[`Prefer: return=representation`](https://www.rfc-editor.org/rfc/rfc7240) and
gets the same 201 response as sync mode once its batch is written
(`Preference-Applied: return=representation`). If that takes longer than
`INGEST_WAIT_TIMEOUT_MS` it gets 202 instead — the entries are still queued.
`Prefer: return=minimal` is the default and is simply acknowledged.

Validation (auth, JSON, required fields, per-entry errors) always happens
before responding; only the write is deferred. Entries are broadcast on the
WebSocket once they are stored, so the live view never shows an entry search
can't find yet.

**Database driver (`DB_DRIVER`).** With MariaDB storage, buffered entries are
written with one multi-row `INSERT` per flush, through either:

- `async` (default) — a non-blocking [ReactPHP MySQL](https://github.com/friends-of-reactphp/mysql)
  connection. The INSERT runs without stalling HTTP or WebSocket traffic.
- `pdo` — the existing blocking PDO connection.

Searches, client management and retention always use PDO.

**When storage fails.** A failed batch goes back to the front of the queue and
is retried with backoff (0.5 s doubling to 30 s). After three failures in a row
the next batch is written entry by entry: if some entries succeed, the ones the
database rejects are bad data and are dropped (logged, counted in
`dropped_total`); if none succeed, everything stays queued. When queued
entries reach `INGEST_BUFFER_CAPACITY` (50 000) new requests get **503** with
`Retry-After: 1` rather than the server running out of memory.

**Shutdown.** On SIGTERM/SIGINT (`systemctl restart`, `docker stop`) the
server stops accepting connections, writes what is queued for up to
`INGEST_SHUTDOWN_TIMEOUT_MS` (8 s, inside systemd's and Docker's 10 s stop
timeout), then exits. Entries can only be lost if the
process is killed without that chance (`kill -9`, OOM, host crash) — at most
about one flush interval's worth.

Watch `GET /api/health` → `ingest` for `queued`, `consecutive_failures` and
`last_error`. Set `INGEST_MODE=sync` to go back to the original behaviour.

### Server's own logs

The server stores its own messages as log entries of the application
**`logstream-server`**, so you can search and watch them like any other app:

| Category | What |
|----------|------|
| `startup`, `storage`, `clients`, `config`, `ingest`, `retention`, `selflog` | Boot messages (what was printed to the console), ending with `LogStream server <version> started` |
| `php.deprecated`, `php.notice` | PHP deprecations and notices (level `notice`) |
| `php.warning`, `php.error` | PHP warnings (`warning`) and recoverable errors (`error`) |
| `php.exception`, `php.fatal` | Uncaught exceptions and fatal errors (`critical`), written straight to storage before the process exits |
| `ingest` | Failed flushes (`warning`) and dropped entries (`error`) |
| `retention`, `http`, `shutdown` | Scheduled retention runs, HTTP server errors, graceful shutdown |
| `http` | Every failed request: 4XX as `warning`, 5XX as `error` (`SELF_LOG_HTTP_ERRORS`) |

PHP errors carry `php_error`, `file` and `line` in `context`; an identical one
is stored at most once every five minutes, with `repeated` counting the ones in
between. Failed requests carry `method`, `path`, `status`, `duration_ms`,
`client_ip`, `user_agent`, `app_key` (the `X-Api-Key`, never a token or the
`Authorization` header) and the response's `error`, `reason` and first
`errors`; the query string and body are not logged. An exception thrown while
handling a request is logged with its class, file and line and answered with
a JSON 500. Every entry of one server process shares a `trace_id`, so a whole run
from boot to shutdown can be pulled up by trace. `app_id` is the host name
unless `SELF_LOG_APP_ID` is set, and `user_agent` is
`LogStream-Server/<version> (PHP <version>; <OS>)`.

The server reports every PHP error type (`E_ALL`), even when `php.ini` hides
deprecations. The console output is unchanged.

```dotenv
SELF_LOG_ENABLED=true     # false: print to the console only, store nothing
SELF_LOG_LEVEL=info       # lowest level stored
SELF_LOG_APP_ID=          # default: host name
SELF_LOG_HTTP_ERRORS=true # false: do not log failed (4XX/5XX) requests
```

`logstream-server` is reserved whether self-logging is on or off: it has no
row in the `clients` table, `POST /api/clients` refuses it (409) and
`POST /api/logs` rejects entries that use it, so no application can write as
the server. `GET /api/info` reports `features.self_logging` and
`self_logging.app_key`.

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

In [logstream-ui](https://github.com/guibranco/logstream-ui), the **Size** view
uses `storage` to stack each day's estimated data and indexes, Seq-style
(data by the day's share of payload bytes, indexes by its share of entries):

![Storage by age in Size mode: per-day data and index size stacked](https://raw.githubusercontent.com/guibranco/logstream-ui/main/docs/screenshots/desktop-retention-size.png)

### Client health (last seen)

In MariaDB mode the server records when each registered client last had
entries accepted (`clients.last_seen_at`), so a client that goes quiet after a
failed deploy or a crash shows up without external monitoring. Apply
`migrations/008_client_health.sql` to add the columns.

- `last_seen_at` is set on every successful ingestion: after the write in
  sync mode, and once the batch is stored in buffered mode. Times are kept in
  memory and written at most every 5 seconds per client (`updated_at` is not
  touched); the API already includes the not-yet-written times.
- `heartbeat_timeout_min` (set with `PUT /api/clients/{app_key}`, `null` to
  disable) is the silence threshold.
- `GET /api/clients` and `GET /api/clients/{app_key}` include `last_seen_at`,
  `heartbeat_timeout_min` and `status`; `GET /api/clients/{app_key}/health`
  returns the status alone:

```json
{
  "app_key":         "billing-api",
  "status":          "active",
  "last_seen":       "2026-04-17T16:45:00+00:00",
  "last_seen_ago":   "3m 22s",
  "timeout_minutes": 10
}
```

`status` is `never_seen` (nothing received yet), `silent` (nothing for longer
than the timeout) or `active` (seen within the timeout, or seen with no
timeout set). It is separate from `active` in the client record, which is the
enabled/disabled flag.

**Silence alerts.** Set `CLIENT_SILENCE_WEBHOOK_URL` and every
`CLIENT_HEALTH_CHECK_SECONDS` (default 30) the server checks enabled clients
that have a heartbeat timeout, and POSTs one alert per silence episode — the
next one fires only after the client has sent again and gone silent again:

```json
{
  "alert":           "client-silent",
  "triggered":       "2026-04-17T17:00:00+00:00",
  "window":          "10m",
  "app_key":         "billing-api",
  "name":            "Billing API",
  "status":          "silent",
  "last_seen":       "2026-04-17T16:13:00+00:00",
  "silent_for":      "47m 0s",
  "timeout_minutes": 10
}
```

Which clients were already alerted is kept in memory, so a client still
silent when the server restarts is reported once more.

### UDP ingestion

For high-frequency logging, `POST /api/logs` costs a TCP handshake, TLS and
HTTP parsing per call. The server can also take **HMAC-signed log packets over
UDP** (`UDP_PORT`, default `8082/udp`) — one entry per datagram, fire and
forget. Packets go through the same pipeline as HTTP (validation, message
templates, ingest buffer, WebSocket feed, client last-seen, retention).

```json
{
  "v":     1,
  "key":   "billing-api",
  "ts":    1748000000000,
  "nonce": "9f2c4e1ab37d5c60",
  "sig":   "<hex HMAC-SHA256>",
  "log":   { "app_id": "production", "level": "info", "category": "payments",
             "message": "Charge processed", "context": { "invoice_id": 1234 } }
}
```

| Field | |
|---|---|
| `v` | Protocol version, `1` |
| `key` | The app_key. MariaDB: a registered client; file mode: any name except `logstream-server` |
| `ts` | Unix time in **milliseconds**; must be within `UDP_TIMESTAMP_WINDOW_MS` (±30 s) of the server — keep clocks NTP-synced |
| `nonce` | 16–64 characters of `A-Z a-z 0-9 _ -`, unique per packet (e.g. 8 random bytes as hex) |
| `sig` | `hex(HMAC-SHA256(secret, "{key}:{ts}:{nonce}:{log}"))`, where `{log}` is the `log` JSON text exactly as it appears in the packet |
| `log` | One entry, as in `POST /api/logs`: `app_id` (required), `level`, `category`, `message` or `message_template` + `context`, `trace_id`, `batch_id`, `user_agent`, `timestamp` |

The **secret** is the client's `api_token` (MariaDB) or `API_SECRET` (file
mode; UDP rejects everything while it is empty). It never travels on the wire.
For `{log}`, encode the `log` object once, sign that text, and embed the same
text in the packet — any JSON encoder works, since the server checks the bytes
it received. (If you pretty-print the packet, the server also accepts a
signature over the compact form: no spaces, keys in the order sent, slashes
and non-ASCII characters not escaped.)

The server **never replies**. A packet that is too large, malformed, stale,
replayed, unsigned or for an unknown/inactive client is dropped and counted in
`GET /api/health` (`udp.rejected`), and summarised in the server's own log at
most once a minute per reason. Deactivating a client or rotating its token
applies to UDP immediately.

```php
// PHP — no dependencies
function sendUdpLog(string $host, int $port, string $appKey, string $secret, array $log): void
{
    $flags   = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    $ts      = (int) (microtime(true) * 1000);
    $nonce   = bin2hex(random_bytes(8));
    $logJson = json_encode($log, $flags);
    $sig     = hash_hmac('sha256', "{$appKey}:{$ts}:{$nonce}:{$logJson}", $secret);
    $packet  = json_encode(['v' => 1, 'key' => $appKey, 'ts' => $ts, 'nonce' => $nonce, 'sig' => $sig, 'log' => $log], $flags);

    $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    socket_sendto($socket, $packet, strlen($packet), 0, $host, $port);
    socket_close($socket);
}

sendUdpLog('logs.example.com', 8082, 'billing-api', 'lss_…', [
    'app_id' => 'production', 'level' => 'info', 'message' => 'Charge processed',
]);
```

```bash
# Shell (testing)
KEY=billing-api; SECRET=lss_…
TS=$(date +%s%3N); NONCE=$(head -c8 /dev/urandom | xxd -p)
LOG='{"app_id":"test","level":"info","message":"hello udp"}'
SIG=$(printf '%s' "${KEY}:${TS}:${NONCE}:${LOG}" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $NF}')
printf '{"v":1,"key":"%s","ts":%s,"nonce":"%s","sig":"%s","log":%s}' "$KEY" "$TS" "$NONCE" "$SIG" "$LOG" \
  | nc -u -w1 127.0.0.1 8082
```

| Variable | Default | |
|---|---|---|
| `UDP_ENABLED` | `true` | Start the UDP listener |
| `UDP_HOST` / `UDP_PORT` | `0.0.0.0` / `8082` | Bind address; the port must differ from `HTTP_PORT` and `WS_PORT` |
| `UDP_MAX_PACKET_SIZE` | `8192` | Larger packets are dropped (max 65507; stay under ~1400 bytes across the internet to avoid fragmentation) |
| `UDP_TIMESTAMP_WINDOW_MS` | `30000` | Accepted clock skew |
| `UDP_NONCE_TTL_S` | `60` | How long nonces are remembered; must be at least 2 × the window |
| `UDP_NONCE_CACHE_MAX` | `200000` | Memory guard on remembered nonces (~100 B each) |
| `UDP_ALLOWED_SOURCES` | *(any)* | Comma-separated IPs / CIDR ranges allowed to send |

Batching (`INGEST_MODE`, `INGEST_BATCH_SIZE`, `INGEST_FLUSH_INTERVAL_MS`,
`INGEST_BUFFER_CAPACITY`) applies to UDP entries too; when the buffer is full,
packets are dropped and counted (`udp.dropped`).

**Security.** UDP has no TLS: the content of each packet is readable in
transit (the secret is not — only the signature is sent). Restrict who can
send with `UDP_ALLOWED_SOURCES` and your firewall, and across the internet
prefer a private network or VPN (e.g. WireGuard). The signature, timestamp
window and per-key nonces stop forged, altered and replayed packets.

**Hosting.** UDP needs a host where you control the ports: a VM, a VPS or
Docker (`8082:8082/udp`). Nginx does not proxy it — clients send straight to
the server. PaaS hosts such as Render accept only HTTP(S), so UDP is off in the
Render blueprint.

### Testing

```bash
composer test:unit                 # no external services
composer test:integration          # file storage always; MariaDB when configured
tests/udp-tests.sh http://localhost:8081 127.0.0.1 8082 udp-ci <API_SECRET> <UI_SECRET>   # live server
```

The MariaDB integration tests run only when `TEST_DB_DSN` is set, and they
**wipe and rebuild that database from `migrations/`**, so point them at a
throwaway one:

```bash
docker run -d --rm --name lsdb -p 3307:3306 -e MARIADB_ROOT_PASSWORD=x -e MARIADB_DATABASE=logstream_test mariadb:11
TEST_DB_DSN="mysql:host=127.0.0.1;port=3307;dbname=logstream_test;charset=utf8mb4" \
TEST_DB_USER=root TEST_DB_PASS=x composer test:integration
```
