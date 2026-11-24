#!/usr/bin/env bash
# Tools/clickhouse-migration.sh
# Applies ClickHouse migration files (migrations/clickhouse/*.sql) in lexicographic
# order over ClickHouse's HTTP interface, skipping files already recorded in the
# schema_versions table of the target database. Only curl is needed.
#
# Usage:
#   Tools/clickhouse-migration.sh [sql_dir] [--dry-run]
#
# Environment:
#   CLICKHOUSE_URL       HTTP endpoint (default: http://127.0.0.1:8123)
#   CLICKHOUSE_DATABASE  Target database, must already exist (default: logstream)
#   CLICKHOUSE_USER      (default: default)
#   CLICKHOUSE_PASSWORD  (default: empty)
#
# File rules (ClickHouse has no transactions, so a failed file is re-run from the top):
#   - one statement per ';' at the end of a line; lines starting with '--' are dropped;
#   - every statement must be idempotent (CREATE … IF NOT EXISTS, ADD COLUMN IF NOT EXISTS, …).
#
# Outputs (for GitHub Actions), same as Tools/db-migration.sh:
#   files          – newline-separated list of files that were / would be applied
#   error          – "true" if an error occurred
#   error_message  – description of the error

set -euo pipefail

SQL_DIR="migrations/clickhouse"
DRY_RUN=false

for ARG in "$@"; do
    case "$ARG" in
        --dry-run) DRY_RUN=true ;;
        *)         SQL_DIR="$ARG" ;;
    esac
done

CH_URL="${CLICKHOUSE_URL:-http://127.0.0.1:8123}"
CH_DATABASE="${CLICKHOUSE_DATABASE:-logstream}"
CH_USER="${CLICKHOUSE_USER:-default}"
CH_PASSWORD="${CLICKHOUSE_PASSWORD:-}"

FILES_APPLIED=()

WORK_DIR=$(mktemp -d)
trap 'rm -rf "$WORK_DIR"' EXIT

# Credentials go in a curl config file (mode 600), not on the command line.
CURL_CONFIG="$WORK_DIR/curl.conf"
umask 077
{
    printf 'header = "X-ClickHouse-User: %s"\n' "$CH_USER"
    printf 'header = "X-ClickHouse-Key: %s"\n' "$CH_PASSWORD"
} > "$CURL_CONFIG"

# ── Helpers ───────────────────────────────────────────────────────────────────

set_output() {
    if [[ -n "${GITHUB_OUTPUT:-}" ]]; then
        echo "$1=$2" >> "$GITHUB_OUTPUT"
    fi
}

set_multiline_output() {
    local key="$1"
    local value="$2"
    if [[ -n "${GITHUB_OUTPUT:-}" ]]; then
        {
            echo "${key}<<EOF"
            echo "$value"
            echo "EOF"
        } >> "$GITHUB_OUTPUT"
    fi
}

fail() {
    set_output "error" "true"
    # GITHUB_OUTPUT values are single-line: flatten ClickHouse's multi-line errors.
    set_output "error_message" "${1//$'\n'/ }"
    echo "❌ $1" >&2
    exit 1
}

# Run one query (read from stdin) against the target database; prints the response.
ch_query() {
    curl --silent --show-error --fail-with-body \
        --config "$CURL_CONFIG" \
        --data-binary @- \
        "${CH_URL%/}/?database=${CH_DATABASE}"
}

# ── Reach the server and ensure schema_versions exists ───────────────────────

echo "SELECT 1" | ch_query > "$WORK_DIR/err" 2>&1 || \
    fail "Cannot reach ClickHouse at ${CH_URL} (database '${CH_DATABASE}'): $(cat "$WORK_DIR/err")"

ch_query > "$WORK_DIR/err" 2>&1 <<'SQL' || fail "Failed to create schema_versions table: $(cat "$WORK_DIR/err")"
CREATE TABLE IF NOT EXISTS schema_versions
(
    filename   String,
    applied_at DateTime DEFAULT now()
)
ENGINE = ReplacingMergeTree
ORDER BY filename
SQL

# ── Collect pending files ─────────────────────────────────────────────────────

if [[ ! -d "$SQL_DIR" ]]; then
    echo "⚠️  SQL directory '$SQL_DIR' not found — nothing to migrate."
    set_output "files" ""
    set_output "error" "false"
    exit 0
fi

APPLIED=$(echo "SELECT DISTINCT filename FROM schema_versions FORMAT TabSeparated" | ch_query) || \
    fail "Failed to read schema_versions"

mapfile -t ALL_FILES < <(find "$SQL_DIR" -maxdepth 1 -name "*.sql" | sort)

PENDING=()
for FILE in "${ALL_FILES[@]}"; do
    if ! grep -qxF "$(basename "$FILE")" <<< "$APPLIED"; then
        PENDING+=("$FILE")
    fi
done

if [[ ${#PENDING[@]} -eq 0 ]]; then
    echo "✅ All ClickHouse migrations already applied — nothing to do."
    set_output "files" ""
    set_output "error" "false"
    exit 0
fi

# ── Apply (or report) pending files ──────────────────────────────────────────

echo ""
if $DRY_RUN; then
    echo "📋 DRY RUN — the following ClickHouse files would be applied:"
else
    echo "🔄 Applying ClickHouse migrations to ${CH_URL} (database '${CH_DATABASE}')…"
fi
echo ""

for FILE in "${PENDING[@]}"; do
    FNAME=$(basename "$FILE")
    echo "  → $FNAME"

    if ! $DRY_RUN; then
        # Split into statements: stmt_0001, stmt_0002, … (the HTTP interface runs one per request).
        rm -f "$WORK_DIR"/stmt_*
        awk -v dir="$WORK_DIR" '
            /^[[:space:]]*--/ { next }
            /^[[:space:]]*$/ && buf == "" { next }
            {
                buf = buf $0 "\n"
                if ($0 ~ /;[[:space:]]*$/) {
                    sub(/;[[:space:]]*\n$/, "\n", buf)
                    n++
                    file = sprintf("%s/stmt_%04d", dir, n)
                    printf "%s", buf > file
                    close(file)
                    buf = ""
                }
            }
            END {
                if (buf ~ /[^[:space:]]/) {
                    n++
                    file = sprintf("%s/stmt_%04d", dir, n)
                    printf "%s", buf > file
                    close(file)
                }
            }
        ' "$FILE"

        shopt -s nullglob
        STATEMENTS=("$WORK_DIR"/stmt_*)
        shopt -u nullglob
        [[ ${#STATEMENTS[@]} -gt 0 ]] || fail "$FNAME contains no statements"

        for STATEMENT in "${STATEMENTS[@]}"; do
            ch_query < "$STATEMENT" > "$WORK_DIR/err" 2>&1 || \
                fail "Failed to apply $FNAME: $(cat "$WORK_DIR/err")"
        done

        printf "INSERT INTO schema_versions (filename) VALUES ('%s')" "$FNAME" | ch_query > /dev/null || \
            fail "Applied $FNAME but could not record it in schema_versions"
    fi

    FILES_APPLIED+=("- \`$FNAME\`")
done

APPLIED_LIST=$(printf "%s\n" "${FILES_APPLIED[@]}")
set_multiline_output "files" "$APPLIED_LIST"
set_output "error" "false"

echo ""
if $DRY_RUN; then
    echo "✅ Dry run complete — ${#FILES_APPLIED[@]} file(s) pending."
else
    echo "✅ Applied ${#FILES_APPLIED[@]} ClickHouse migration(s) successfully."
fi
