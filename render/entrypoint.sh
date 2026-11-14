#!/usr/bin/env bash
# render/entrypoint.sh
# Starts the LogStream server (HTTP + WebSocket on internal ports) and Caddy on
# the public $PORT. If either process exits, the other is stopped and the
# container exits with that status so the host restarts it.

set -euo pipefail

export PORT="${PORT:-10000}"
export HTTP_PORT="${HTTP_PORT:-8081}"
export WS_PORT="${WS_PORT:-8080}"

if [[ "$PORT" == "$HTTP_PORT" || "$PORT" == "$WS_PORT" ]]; then
    echo "❌ PORT ($PORT) must differ from HTTP_PORT ($HTTP_PORT) and WS_PORT ($WS_PORT)."
    exit 1
fi

# Show the deployed commit in /api/health when the host provides it.
if [[ -z "${APP_VERSION:-}" && -n "${RENDER_GIT_COMMIT:-}" ]]; then
    export APP_VERSION="${RENDER_GIT_COMMIT:0:7}"
fi

echo "🚀 Starting LogStream (HTTP :$HTTP_PORT, WS :$WS_PORT) behind Caddy on :$PORT"

php bin/server.php &
PHP_PID=$!

caddy run --config /etc/caddy/Caddyfile --adapter caddyfile &
CADDY_PID=$!

shutdown() {
    kill -TERM "$PHP_PID" "$CADDY_PID" 2>/dev/null || true
}
trap shutdown TERM INT

STATUS=0
wait -n "$PHP_PID" "$CADDY_PID" || STATUS=$?

echo "⚠️  A process exited (status $STATUS); stopping the container."
shutdown
wait || true
exit "$STATUS"
