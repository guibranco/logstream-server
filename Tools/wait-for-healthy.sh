#!/usr/bin/env bash
# Tools/wait-for-healthy.sh
# Polls Docker until every container in the compose project is healthy.
# Usage: Tools/wait-for-healthy.sh [timeout_seconds]

set -euo pipefail

TIMEOUT="${1:-120}"
INTERVAL=3
ELAPSED=0

echo "⏳ Waiting for all containers to be healthy (timeout: ${TIMEOUT}s)…"

while true; do
    # Count containers that are NOT yet healthy
    UNHEALTHY=$(docker ps --format '{{.Status}}' | grep -v "healthy" | grep -c "health" || true)
    STARTING=$(docker ps --format '{{.Status}}' | grep -c "starting" || true)

    if [[ "$UNHEALTHY" -eq 0 && "$STARTING" -eq 0 ]]; then
        echo "✅ All containers are healthy."
        docker ps --format 'table {{.Names}}\t{{.Status}}'
        exit 0
    fi

    if [[ "$ELAPSED" -ge "$TIMEOUT" ]]; then
        echo "❌ Timed out after ${TIMEOUT}s waiting for containers."
        docker ps --format 'table {{.Names}}\t{{.Status}}'
        docker compose logs --tail=50
        exit 1
    fi

    echo "   Still waiting… (${ELAPSED}s elapsed, unhealthy/starting: $((UNHEALTHY + STARTING)))"
    sleep "$INTERVAL"
    ELAPSED=$((ELAPSED + INTERVAL))
done
