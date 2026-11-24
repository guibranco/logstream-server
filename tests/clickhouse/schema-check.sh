#!/usr/bin/env bash
# tests/clickhouse/schema-check.sh
# Checks the ClickHouse log store schema on a live server, after
# Tools/clickhouse-migration.sh has run. Used by CI; also handy locally:
#
#   docker compose --profile clickhouse up -d clickhouse
#   export CLICKHOUSE_URL=http://127.0.0.1:8123 CLICKHOUSE_USER=logstream CLICKHOUSE_PASSWORD=test
#   bash Tools/clickhouse-migration.sh && bash tests/clickhouse/schema-check.sh
#
# Writes to log_entries and truncates it at the end: never point it at production.

set -euo pipefail

CH_URL="${CLICKHOUSE_URL:-http://127.0.0.1:8123}"
CH_DATABASE="${CLICKHOUSE_DATABASE:-logstream}"
CH_USER="${CLICKHOUSE_USER:-default}"
CH_PASSWORD="${CLICKHOUSE_PASSWORD:-}"
EXPECT_MEMORY_CAP="${EXPECT_MEMORY_CAP:-}"

WORK_DIR=$(mktemp -d)
trap 'rm -rf "$WORK_DIR"' EXIT
umask 077
{
    printf 'header = "X-ClickHouse-User: %s"\n' "$CH_USER"
    printf 'header = "X-ClickHouse-Key: %s"\n' "$CH_PASSWORD"
} > "$WORK_DIR/curl.conf"

FAILURES=0

q() {
    curl --silent --show-error --fail-with-body --config "$WORK_DIR/curl.conf" \
        --data-binary @- "${CH_URL%/}/?database=${CH_DATABASE}"
}

check() {
    local name="$1" expected="$2" actual="$3"
    if [[ "$actual" == "$expected" ]]; then
        echo "✅ $name"
    else
        echo "❌ $name"
        echo "   expected: $expected"
        echo "   actual:   $actual"
        FAILURES=$((FAILURES + 1))
    fi
}

echo "TRUNCATE TABLE log_entries" | q

# ── Columns ───────────────────────────────────────────────────────────────────

COLUMNS=$(echo "SELECT groupArray(name) FROM (SELECT name FROM system.columns WHERE database = currentDatabase() AND table = 'log_entries' ORDER BY position) FORMAT TabSeparated" | q)
check "columns" \
    "['id','trace_id','batch_id','app_key','app_id','user_agent','sender_ip','transport','level','category','message','message_template','context','timestamp','created_at']" \
    "$COLUMNS"

ENGINE=$(echo "SELECT engine, partition_key, sorting_key FROM system.tables WHERE database = currentDatabase() AND name = 'log_entries' FORMAT TabSeparated" | q)
check "engine, partition and sorting key" \
    "$(printf 'ReplacingMergeTree\ttoYYYYMMDD(timestamp)\tapp_key, timestamp, id')" \
    "$ENGINE"

INDEXES=$(echo "SELECT groupArray(name) FROM (SELECT name FROM system.data_skipping_indices WHERE database = currentDatabase() AND table = 'log_entries' ORDER BY name) FORMAT TabSeparated" | q)
check "skipping indexes" \
    "['idx_batch_id','idx_context','idx_id','idx_message','idx_message_template','idx_trace_id']" \
    "$INDEXES"

# ── Round trip (LogEntry::toArray() shape) ────────────────────────────────────

# Recent days, so the 35-day TTL backstop never removes the rows mid-test.
DAY1=$(date -u -d '2 days ago' +%Y-%m-%d)
DAY2=$(date -u -d '1 day ago' +%Y-%m-%d)
PART1=${DAY1//-/}
PART2=${DAY2//-/}

cat > "$WORK_DIR/rows.jsonl" <<JSON
{"id":"01JDXS5G8QW3Z6V9N2M4K7H1PA","trace_id":"7d1e6f2a-3b4c-4d5e-8f90-a1b2c3d4e5f6","batch_id":"b-1","app_key":"billing-api","app_id":"vinhedo5","user_agent":"BillingService/2.1.0","sender_ip":"203.0.113.7","transport":"udp","level":"error","category":"Payments","message":"Card declined for order 42","message_template":"Card declined for order {orderId}","context":"{\"orderId\":42,\"user\":{\"role\":\"admin\"}}","timestamp":"${DAY1} 10:00:00.123456"}
{"id":"01JDXS5G8QW3Z6V9N2M4K7H1PB","trace_id":"t-2","batch_id":null,"app_key":"logstream-server","app_id":"vinhedo5","user_agent":null,"sender_ip":null,"transport":null,"level":"info","category":"startup","message":"LogStream server started","message_template":null,"context":null,"timestamp":"${DAY2} 08:30:00.000001"}
JSON

insert_rows() {
    q < <(printf 'INSERT INTO log_entries (id, trace_id, batch_id, app_key, app_id, user_agent, sender_ip, transport, level, category, message, message_template, context, timestamp) FORMAT JSONEachRow\n'; cat "$WORK_DIR/rows.jsonl")
}

insert_rows
# The same batch again, as a retry after a timeout would send it.
insert_rows
echo "OPTIMIZE TABLE log_entries FINAL" | q

check "a retried batch is deduplicated" "2" "$(echo "SELECT count() FROM log_entries" | q)"

check "values round-trip" \
    "$(printf 'error\tPayments\tudp\t203.0.113.7\tCard declined for order {orderId}\t42\tadmin\t%s 10:00:00.123456' "$DAY1")" \
    "$(echo "SELECT level, category, transport, sender_ip, message_template, JSONExtractInt(context, 'orderId'), JSONExtractString(context, 'user', 'role'), timestamp FROM log_entries WHERE id = '01JDXS5G8QW3Z6V9N2M4K7H1PA' FORMAT TabSeparated" | q)"

check "nulls stay null" \
    "$(printf '1\t1\t1\t1\t1')" \
    "$(echo "SELECT isNull(batch_id), isNull(transport), isNull(sender_ip), isNull(message_template), isNull(context) FROM log_entries WHERE id = '01JDXS5G8QW3Z6V9N2M4K7H1PB' FORMAT TabSeparated" | q)"

check "one partition per day" \
    "${PART1},${PART2}" \
    "$(echo "SELECT arrayStringConcat(groupArray(partition), ',') FROM (SELECT DISTINCT partition FROM system.parts WHERE database = currentDatabase() AND table = 'log_entries' AND active ORDER BY partition) FORMAT TabSeparated" | q)"

check "level filter uses the enum" "1" "$(echo "SELECT count() FROM log_entries WHERE level IN ('error', 'critical')" | q)"

check "case-insensitive message search" "1" "$(echo "SELECT count() FROM log_entries WHERE lower(message) LIKE '%declined%order%'" | q)"

# Dropping a day is how "older than N days" retention will work (#158).
echo "ALTER TABLE log_entries DROP PARTITION ${PART1}" | q
check "drop a day partition" "1" "$(echo "SELECT count() FROM log_entries" | q)"

# ── Server limits (docker/clickhouse/config.d/logstream.xml) ─────────────────

if [[ -n "$EXPECT_MEMORY_CAP" ]]; then
    check "max_server_memory_usage" "$EXPECT_MEMORY_CAP" \
        "$(echo "SELECT value FROM system.server_settings WHERE name = 'max_server_memory_usage' FORMAT TabSeparated" | q)"
    check "max_threads for the logstream user" "2" \
        "$(echo "SELECT value FROM system.settings WHERE name = 'max_threads' FORMAT TabSeparated" | q)"
fi

echo "TRUNCATE TABLE log_entries" | q

echo ""
if [[ $FAILURES -gt 0 ]]; then
    echo "❌ $FAILURES check(s) failed"
    exit 1
fi
echo "✅ ClickHouse schema checks passed"
