#!/usr/bin/env bash
# Tools/db-migration.sh
# Applies SQL migration files from a directory in lexicographic order.
# Skips files that have already been recorded in the schema_versions table.
#
# Usage:
#   Tools/db-migration.sh <sql_dir> <host> <user> <database> [--dry-run]
#
# Outputs (for GitHub Actions):
#   files          – newline-separated list of files that were / would be applied
#   error          – "true" if an error occurred
#   error_message  – description of the error

set -euo pipefail

SQL_DIR="${1:-migrations}"
DB_HOST="${2:-localhost}"
DB_USER="${3:-root}"
DB_NAME="${4:-logservice}"
DRY_RUN=false

if [[ "${5:-}" == "--dry-run" ]]; then
    DRY_RUN=true
fi

FILES_APPLIED=()
ERROR=false
ERROR_MSG=""

# ── Helpers ───────────────────────────────────────────────────────────────────

mysql_cmd() {
    mysql -h "$DB_HOST" -u "$DB_USER" "$DB_NAME" --batch --silent -e "$1" 2>&1
}

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

# ── Ensure schema_versions table exists ───────────────────────────────────────

mysql_cmd "
CREATE TABLE IF NOT EXISTS schema_versions (
    filename    VARCHAR(255) NOT NULL,
    applied_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (filename)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
" || {
    ERROR_MSG="Failed to create schema_versions table"
    set_output "error" "true"
    set_output "error_message" "$ERROR_MSG"
    echo "❌ $ERROR_MSG" >&2
    exit 1
}

# ── Collect pending files ─────────────────────────────────────────────────────

if [[ ! -d "$SQL_DIR" ]]; then
    echo "⚠️  SQL directory '$SQL_DIR' not found — nothing to migrate."
    set_output "files" ""
    set_output "error" "false"
    exit 0
fi

mapfile -t ALL_FILES < <(find "$SQL_DIR" -maxdepth 1 -name "*.sql" | sort)

PENDING=()
for FILE in "${ALL_FILES[@]}"; do
    FNAME=$(basename "$FILE")
    EXISTS=$(mysql_cmd "SELECT COUNT(*) FROM schema_versions WHERE filename='$FNAME';")
    if [[ "$EXISTS" == "0" ]]; then
        PENDING+=("$FILE")
    fi
done

if [[ ${#PENDING[@]} -eq 0 ]]; then
    echo "✅ All migrations already applied — nothing to do."
    set_output "files" ""
    set_output "error" "false"
    exit 0
fi

# ── Apply (or report) pending files ──────────────────────────────────────────

echo ""
if $DRY_RUN; then
    echo "📋 DRY RUN — the following files would be applied:"
else
    echo "🔄 Applying migrations…"
fi
echo ""

for FILE in "${PENDING[@]}"; do
    FNAME=$(basename "$FILE")
    echo "  → $FNAME"

    if ! $DRY_RUN; then
        mysql -h "$DB_HOST" -u "$DB_USER" "$DB_NAME" < "$FILE" 2>&1 || {
            ERROR_MSG="Failed to apply $FNAME"
            ERROR=true
            set_output "error" "true"
            set_output "error_message" "$ERROR_MSG"
            echo "❌ $ERROR_MSG" >&2
            exit 1
        }

        mysql_cmd "INSERT IGNORE INTO schema_versions (filename) VALUES ('$FNAME');"
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
    echo "✅ Applied ${#FILES_APPLIED[@]} migration(s) successfully."
fi
