#!/usr/bin/env bash
# Tests/endpoint-tests.sh
# Hits the live HTTP server and validates responses.
# Usage: Tests/endpoint-tests.sh <base_url> <api_secret> <ui_secret>
# Example: Tests/endpoint-tests.sh http://localhost:8081 my-write-secret my-read-secret

set -euo pipefail

BASE_URL="${1:-http://localhost:8081}"
API_SECRET="${2:-test-secret}"
UI_SECRET="${3:-test-ui-secret}"
PASS=0
FAIL=0
ERRORS=()

# ── Helpers ───────────────────────────────────────────────────────────────────

green()  { echo -e "\033[0;32m✅  $*\033[0m"; }
red()    { echo -e "\033[0;31m❌  $*\033[0m"; }
header() { echo -e "\n\033[1;36m── $* ──\033[0m"; }

assert_status() {
    local label="$1"
    local expected="$2"
    local actual="$3"

    if [[ "$actual" == "$expected" ]]; then
        green "$label → HTTP $actual"
        ((PASS++))
    else
        red "$label → expected HTTP $expected, got HTTP $actual"
        ERRORS+=("$label: expected $expected got $actual")
        ((FAIL++))
    fi
}

assert_json_field() {
    local label="$1"
    local expected="$2"
    local actual="$3"

    if [[ "$actual" == "$expected" ]]; then
        green "$label → $actual"
        ((PASS++))
    else
        red "$label → expected '$expected', got '$actual'"
        ERRORS+=("$label: expected '$expected' got '$actual'")
        ((FAIL++))
    fi
}

json_field() {
    echo "$1" | grep -oP "\"$2\"\\s*:\\s*\"?\\K[^\",}]+" | head -1
}

# ── 1. Health (public — no auth required) ─────────────────────────────────────

header "Health endpoint"

RESPONSE=$(curl -s -o /tmp/ls_health_body.json -w "%{http_code}" \
    "$BASE_URL/api/health")

assert_status "GET /api/health" "200" "$RESPONSE"

BODY=$(cat /tmp/ls_health_body.json)
STATUS_VAL=$(json_field "$BODY" "status")
assert_json_field "health.status == ok" "ok" "$STATUS_VAL"

# ── 2. Auth guards ────────────────────────────────────────────────────────────

header "Auth guards"

# POST without API_SECRET → 401
RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" \
    -X POST "$BASE_URL/api/logs" \
    -H "Content-Type: application/json" \
    -d '{"app_key":"x","app_id":"y","message":"no auth"}')

assert_status "POST /api/logs without auth → 401" "401" "$RESPONSE"

# GET without UI_SECRET → 401
RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" \
    "$BASE_URL/api/logs")

assert_status "GET /api/logs without auth → 401" "401" "$RESPONSE"

# GET with wrong token → 401
RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" \
    -H "Authorization: Bearer wrong-token" \
    "$BASE_URL/api/logs")

assert_status "GET /api/logs with wrong token → 401" "401" "$RESPONSE"

# POST with UI_SECRET on a write endpoint → 401 (wrong key for wrong endpoint)
RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" \
    -X POST "$BASE_URL/api/logs" \
    -H "Content-Type: application/json" \
    -H "Authorization: Bearer $UI_SECRET" \
    -d '{"app_key":"x","app_id":"y","message":"ui key on write"}')

assert_status "POST /api/logs with UI_SECRET → 401" "401" "$RESPONSE"

# ── 3. Ingest – single entry ──────────────────────────────────────────────────

header "Ingest single entry"

INGEST_BODY=$(cat <<JSONEOF
{
  "app_key":  "endpoint-test",
  "app_id":   "ci",
  "level":    "info",
  "category": "ci-test",
  "message":  "Hello from endpoint test",
  "context":  {"run": "ci"}
}
JSONEOF
)

RESPONSE=$(curl -s -o /tmp/ls_ingest_body.json -w "%{http_code}" \
    -X POST "$BASE_URL/api/logs" \
    -H "Content-Type: application/json" \
    -H "Authorization: Bearer $API_SECRET" \
    -H "User-Agent: EndpointTest/1.0" \
    -d "$INGEST_BODY")

assert_status "POST /api/logs (single)" "201" "$RESPONSE"

SAVED=$(json_field "$(cat /tmp/ls_ingest_body.json)" "saved")
assert_json_field "saved == 1" "1" "$SAVED"

# Extract the IDs for later assertions
ENTRY_ID=$(cat /tmp/ls_ingest_body.json | grep -oP '"id":\s*"\K[^"]+' | head -1)
TRACE_ID=$(cat /tmp/ls_ingest_body.json | grep -oP '"trace_id":\s*"\K[^"]+' | head -1)

# ── 4. Ingest – batch ─────────────────────────────────────────────────────────

header "Ingest batch"

BATCH_ID="test-batch-$(date +%s)"

BATCH_BODY=$(cat <<JSONEOF
{
  "app_key":  "endpoint-test",
  "app_id":   "ci",
  "batch_id": "$BATCH_ID",
  "logs": [
    {"level": "warning", "category": "ci-batch", "message": "Batch entry 1"},
    {"level": "error",   "category": "ci-batch", "message": "Batch entry 2"},
    {"level": "debug",   "category": "ci-batch", "message": "Batch entry 3"}
  ]
}
JSONEOF
)

RESPONSE=$(curl -s -o /tmp/ls_batch_body.json -w "%{http_code}" \
    -X POST "$BASE_URL/api/logs" \
    -H "Content-Type: application/json" \
    -H "Authorization: Bearer $API_SECRET" \
    -d "$BATCH_BODY")

assert_status "POST /api/logs (batch of 3)" "201" "$RESPONSE"

SAVED=$(json_field "$(cat /tmp/ls_batch_body.json)" "saved")
assert_json_field "saved == 3" "3" "$SAVED"

# ── 5. Get by ID ──────────────────────────────────────────────────────────────

header "Get entry by ID"

if [[ -n "$ENTRY_ID" ]]; then
    RESPONSE=$(curl -s -o /tmp/ls_getbyid_body.json -w "%{http_code}" \
        -H "Authorization: Bearer $UI_SECRET" \
        "$BASE_URL/api/logs/$ENTRY_ID")
    assert_status "GET /api/logs/$ENTRY_ID" "200" "$RESPONSE"

    FOUND_ID=$(json_field "$(cat /tmp/ls_getbyid_body.json)" "id")
    assert_json_field "entry id matches" "$ENTRY_ID" "$FOUND_ID"
else
    red "Skipping get-by-id: no entry ID captured from ingest step"
    ((FAIL++))
fi

# ── 6. Get by trace ID ────────────────────────────────────────────────────────

header "Get entry by trace ID"

if [[ -n "$TRACE_ID" ]]; then
    RESPONSE=$(curl -s -o /tmp/ls_gettrace_body.json -w "%{http_code}" \
        -H "Authorization: Bearer $UI_SECRET" \
        "$BASE_URL/api/logs/$TRACE_ID")
    assert_status "GET /api/logs/$TRACE_ID (trace)" "200" "$RESPONSE"
fi

# ── 7. Search ─────────────────────────────────────────────────────────────────

header "Search"

RESPONSE=$(curl -s -o /tmp/ls_search_body.json -w "%{http_code}" \
    -H "Authorization: Bearer $UI_SECRET" \
    "$BASE_URL/api/logs?app_key=endpoint-test&app_id=ci")

assert_status "GET /api/logs?app_key=endpoint-test" "200" "$RESPONSE"

TOTAL=$(json_field "$(cat /tmp/ls_search_body.json)" "total")
if [[ "$TOTAL" -ge 4 ]]; then
    green "search total >= 4 (got $TOTAL)"
    ((PASS++))
else
    red "search total should be >= 4, got '$TOTAL'"
    ERRORS+=("search total: expected >=4 got $TOTAL")
    ((FAIL++))
fi

# ── 8. Search by batch_id ─────────────────────────────────────────────────────

header "Search by batch_id"

RESPONSE=$(curl -s -o /tmp/ls_batch_search.json -w "%{http_code}" \
    -H "Authorization: Bearer $UI_SECRET" \
    "$BASE_URL/api/logs?batch_id=$BATCH_ID")

assert_status "GET /api/logs?batch_id=$BATCH_ID" "200" "$RESPONSE"

BTOTAL=$(json_field "$(cat /tmp/ls_batch_search.json)" "total")
assert_json_field "batch search total == 3" "3" "$BTOTAL"

# ── 9. Search by level ────────────────────────────────────────────────────────

header "Search by level"

RESPONSE=$(curl -s -o /tmp/ls_level_search.json -w "%{http_code}" \
    -H "Authorization: Bearer $UI_SECRET" \
    "$BASE_URL/api/logs?app_key=endpoint-test&level=error")

assert_status "GET /api/logs?level=error" "200" "$RESPONSE"

# ── 10. 404 for unknown entry ─────────────────────────────────────────────────

header "Not found"

RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" \
    -H "Authorization: Bearer $UI_SECRET" \
    "$BASE_URL/api/logs/00000000000000000000000000")

assert_status "GET /api/logs/nonexistent → 404" "404" "$RESPONSE"

# ── 11. CORS preflight ────────────────────────────────────────────────────────

header "CORS preflight"

RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" \
    -X OPTIONS "$BASE_URL/api/logs" \
    -H "Origin: http://localhost:5173" \
    -H "Access-Control-Request-Method: POST")

assert_status "OPTIONS /api/logs → 204" "204" "$RESPONSE"

# ── Summary ───────────────────────────────────────────────────────────────────

echo ""
echo "═══════════════════════════════════════"
echo " Results: $PASS passed, $FAIL failed"
echo "═══════════════════════════════════════"

if [[ "$FAIL" -gt 0 ]]; then
    echo ""
    echo "Failed assertions:"
    for err in "${ERRORS[@]}"; do
        echo "  • $err"
    done
    echo ""
    echo "error=true"   >> "${GITHUB_OUTPUT:-/dev/null}"
    echo "failed=$FAIL" >> "${GITHUB_OUTPUT:-/dev/null}"
    exit 1
fi

echo "error=false" >> "${GITHUB_OUTPUT:-/dev/null}"
exit 0
