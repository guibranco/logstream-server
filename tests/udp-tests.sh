#!/usr/bin/env bash
# tests/udp-tests.sh
# Sends HMAC-signed UDP packets to a live server and checks, over HTTP, that
# valid ones are stored and forged / replayed ones are not. Packets are signed
# in Python (not PHP) to prove a second language produces matching signatures.
#
# Usage:
#   tests/udp-tests.sh <base_url> <udp_host> <udp_port> <app_key> <secret> <ui_secret>
#
#   File storage:  <app_key> is any name, <secret> is API_SECRET.
#   MariaDB:       <app_key> / <secret> are a client's app_key / api_token.
#
# Example:
#   tests/udp-tests.sh http://localhost:8081 127.0.0.1 8082 udp-ci test-secret test-ui-secret

set -euo pipefail

BASE_URL="${1:-http://localhost:8081}"
UDP_HOST="${2:-127.0.0.1}"
UDP_PORT="${3:-8082}"
APP_KEY="${4:-udp-ci}"
SECRET="${5:-test-secret}"
UI_SECRET="${6:-test-ui-secret}"

RUN_ID="udp-$(date +%s)-$$"
PASS=0
FAIL=0

green() { echo -e "\033[0;32m✅  $*\033[0m"; PASS=$((PASS + 1)); }
red()   { echo -e "\033[0;31m❌  $*\033[0m"; FAIL=$((FAIL + 1)); }

# expect <actual> <expected> <pass message> <fail message>
expect() {
    if [[ "$1" == "$2" ]]; then
        green "$3"
    else
        red "$4"
    fi
}

# send <message> [secret] [nonce] — prints the nonce used
send() {
    python3 - "$UDP_HOST" "$UDP_PORT" "$APP_KEY" "${2:-$SECRET}" "$1" "${3:-}" "$RUN_ID" <<'PY'
import hashlib, hmac, json, os, socket, sys, time
host, port, key, secret, message, nonce, run_id = sys.argv[1:8]
nonce = nonce or os.urandom(8).hex()
ts = int(time.time() * 1000)
log = {"app_id": "udp-ci", "level": "info", "category": run_id, "message": message,
       "context": {"run": run_id, "url": "https://example.com/a/b", "text": "Olá ✓", "empty": {}}}
log_json = json.dumps(log, separators=(",", ":"), ensure_ascii=False)
sig = hmac.new(secret.encode(), f"{key}:{ts}:{nonce}:{log_json}".encode(), hashlib.sha256).hexdigest()
packet = '{"v":1,"key":%s,"ts":%d,"nonce":"%s","sig":"%s","log":%s}' % (json.dumps(key), ts, nonce, sig, log_json)
socket.socket(socket.AF_INET, socket.SOCK_DGRAM).sendto(packet.encode(), (host, int(port)))
print(nonce)
PY
}

count_messages() {
    curl -fsS -G "${BASE_URL}/api/logs" \
        -H "Authorization: Bearer ${UI_SECRET}" \
        --data-urlencode "category=${RUN_ID}" \
        --data-urlencode "search=$1" \
        | python3 -c 'import json, sys; print(json.load(sys.stdin)["total"])'
}

echo "── UDP ingest (${UDP_HOST}:${UDP_PORT}, run ${RUN_ID}) ──"

if curl -fsS "${BASE_URL}/api/info" | python3 -c 'import json, sys; sys.exit(0 if json.load(sys.stdin)["features"].get("udp_ingest") else 1)'; then
    green "/api/info reports udp_ingest"
else
    red "/api/info does not report udp_ingest — is UDP_ENABLED=true?"
fi

nonce=$(send "signed packet ${RUN_ID}")
send "forged packet ${RUN_ID}" "wrong-secret" >/dev/null
send "replayed packet ${RUN_ID}" "" "$nonce" >/dev/null   # same nonce, new content: replay

# Buffered ingest writes every INGEST_FLUSH_INTERVAL_MS (500 ms by default).
stored=0
for _ in $(seq 1 20); do
    stored=$(count_messages "signed packet ${RUN_ID}")
    [[ "$stored" == "1" ]] && break
    sleep 0.5
done

expect "$stored" 1 "signed packet stored" "signed packet not stored (found ${stored})"
expect "$(count_messages "forged packet ${RUN_ID}")" 0 "forged packet dropped" "forged packet was stored"
expect "$(count_messages "replayed packet ${RUN_ID}")" 0 "replayed nonce dropped" "replayed nonce was stored"

health=$(curl -fsS "${BASE_URL}/api/health")
if python3 -c 'import json, sys; u = json.loads(sys.argv[1])["udp"]; sys.exit(0 if u["listening"] and u["accepted"] >= 1 and u["rejected"].get("bad_signature", 0) >= 1 and u["rejected"].get("replay", 0) >= 1 else 1)' "$health"; then
    green "/api/health counts accepted, bad_signature and replay"
else
    red "/api/health UDP counters unexpected: $(python3 -c 'import json, sys; print(json.loads(sys.argv[1]).get("udp"))' "$health")"
fi

echo
echo "UDP tests: ${PASS} passed, ${FAIL} failed"
[[ "$FAIL" == "0" ]]
