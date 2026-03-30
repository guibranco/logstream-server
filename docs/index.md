---
layout: home
title: Home
nav_order: 1
permalink: /
---

# logstream-server
{: .fs-9 }

A lightweight, real-time log ingestion and streaming service built with PHP 8.3 and ReactPHP.
{: .fs-6 .fw-300 }

[Get started](#quick-start){: .btn .btn-primary .fs-5 .mb-4 .mb-md-0 .mr-2 }
[View on GitHub](https://github.com/guibranco/logstream-server){: .btn .fs-5 .mb-4 .mb-md-0 }

---

## Overview

logstream-server receives log messages from your applications over HTTP and streams them to a browser-based UI in real-time over WebSockets. It supports two storage backends — a flat-file JSONL store for zero-dependency setups, and MariaDB for higher-volume production deployments.

```
[ Your apps ]  ── POST /api/logs ──▶  [ HTTP :8081 ]
                                              │
                                         saves to storage
                                         (MariaDB / JSONL)
                                              │
                                         broadcasts via
                                              ▼
                                      [ WS :8080 ] ──▶  [ React UI ]
```

---

## Features

- **Real-time streaming** — every ingested log is immediately broadcast to all connected UI clients via WebSocket
- **Dual auth** — separate write key (`API_SECRET`) for your apps and read key (`UI_SECRET`) for the UI
- **Batch ingestion** — send many log entries in a single HTTP request under a shared `batch_id`
- **Trace IDs** — correlate entries across services with `trace_id` (UUID) and `batch_id`
- **Rich search** — filter by application, user agent, level, category, date range, trace, batch, or free-text message search
- **Two storage backends** — JSONL daily files (zero deps) or MariaDB (production scale)
- **ULID primary keys** — time-sortable IDs that double as an implicit `ORDER BY timestamp`

---

## Quick start

### 1. Clone and configure

```bash
git clone https://github.com/guibranco/logstream-server.git
cd logstream-server
cp .env.example .env
# Edit .env and set API_SECRET and UI_SECRET
```

### 2. Install dependencies

```bash
composer install --no-dev --optimize-autoloader
```

### 3. Start the server

```bash
php bin/server.php
```

The server starts two listeners:

| Port | Protocol | Purpose |
|------|----------|---------|
| `8081` | HTTP | REST API — ingest and search logs |
| `8080` | WebSocket | Real-time push to the UI |

### 4. Send your first log

```bash
curl -s -X POST http://localhost:8081/api/logs \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <API_SECRET>" \
  -H "User-Agent: MyApp/1.0" \
  -d '{
    "app_key":  "my-app",
    "app_id":   "production",
    "level":    "info",
    "category": "startup",
    "message":  "Hello, logstream!"
  }'
```

---

## API reference

### Authentication

| Endpoint | Key required |
|----------|-------------|
| `POST /api/logs` | `Authorization: Bearer <API_SECRET>` |
| `GET /api/logs` | `Authorization: Bearer <UI_SECRET>` |
| `GET /api/logs/:id` | `Authorization: Bearer <UI_SECRET>` |
| `GET /api/health` | None — public |

### Log entry fields

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `app_key` | string ≤ 100 | ✅ | Application slug, e.g. `billing-api` |
| `app_id` | string ≤ 100 | ✅ | Instance / environment, e.g. `production` |
| `message` | string | ✅ | Human-readable description |
| `level` | enum | ❌ | `debug` `info` `notice` `warning` `error` `critical` (default: `info`) |
| `category` | string ≤ 100 | ❌ | Free-form grouping tag (default: `general`) |
| `trace_id` | UUID | ❌ | Client-supplied correlation ID; auto-generated if omitted |
| `batch_id` | UUID | ❌ | Groups entries from the same request or job |
| `user_agent` | string | ❌ | Captured from the `User-Agent` header automatically |
| `context` | object | ❌ | Arbitrary JSON payload |
| `timestamp` | ISO 8601 | ❌ | When the event occurred; defaults to server receive time |

### Batch ingestion

Send multiple entries in one request by placing them in a `logs` array. Fields set at the top level (`app_key`, `app_id`, `batch_id`) are inherited by every entry in the array:

```json
{
  "app_key":  "billing-api",
  "app_id":   "production",
  "batch_id": "550e8400-e29b-41d4-a716-446655440000",
  "logs": [
    { "level": "info",    "category": "payments", "message": "Charge initiated" },
    { "level": "error",   "category": "payments", "message": "Charge declined", "context": { "code": "card_declined" } }
  ]
}
```

### Search parameters

`GET /api/logs` accepts the following query parameters:

| Parameter | Description |
|-----------|-------------|
| `app_key` | Exact match |
| `app_id` | Exact match |
| `level` | Exact match |
| `category` | Partial match |
| `user_agent` | Partial match |
| `trace_id` | Exact match |
| `batch_id` | Exact match |
| `date_from` | ISO 8601 — inclusive lower bound |
| `date_to` | ISO 8601 — inclusive upper bound |
| `search` | Substring match on `message` |
| `limit` | Max entries returned (default `100`, max `1000`) |
| `offset` | Pagination offset (default `0`) |

---

## WebSocket

Connect to `ws://host:8080?token=<UI_SECRET>` (or `wss://` when behind Nginx with TLS).

Every ingested log entry is broadcast as:

```json
{
  "type": "log",
  "data": { ...LogEntry }
}
```

The client can also send `{ "type": "ping" }` to receive a `{ "type": "pong" }` keepalive response.

---

## Deployment guides

Choose the guide that matches your environment:

- [**Ubuntu**](./deploy-ubuntu) — PHP 8.3 + systemd + Nginx + Certbot
- [**Windows**](./deploy-windows) — PHP 8.3 + NSSM Windows Service + optional Nginx
- [**Docker**](./deploy-docker) — Docker Compose + Nginx container + Certbot container
