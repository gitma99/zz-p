#!/usr/bin/env bash
# Tests that tools/run-migrations.php applies all migrations and is idempotent.
#
# Usage:
#   MYSQL_HOST=127.0.0.1 MYSQL_USER=root MYSQL_PASS=secret \\
#   bash tests/integration/run-migrations_test.sh
#
# Env:
#   MYSQL_HOST   default 127.0.0.1
#   MYSQL_PORT   default 3306
#   MYSQL_USER   default root
#   MYSQL_PASS   default empty
#   PHP_BIN      default php
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
while [ ! -f "$REPO_ROOT/sql/sql.php" ] && [ "$REPO_ROOT" != "/" ]; do
    REPO_ROOT="$(dirname "$REPO_ROOT")"
done
[ -f "$REPO_ROOT/sql/sql.php" ] || { echo "FAIL: cannot locate sql/sql.php" >&2; exit 1; }

MYSQL_HOST="${MYSQL_HOST:-127.0.0.1}"
MYSQL_PORT="${MYSQL_PORT:-3306}"
MYSQL_USER="${MYSQL_USER:-root}"
MYSQL_PASS="${MYSQL_PASS:-}"
TEST_DB="zzpanel_migration_test_$$"
PHP_BIN="${PHP_BIN:-php}"

# --- create the database via the system 'mysql' database (always exists) ---
MYSQL_HOST="$MYSQL_HOST" MYSQL_PORT="$MYSQL_PORT" MYSQL_USER="$MYSQL_USER" MYSQL_PASS="$MYSQL_PASS" MYSQL_DB=mysql \
php "$REPO_ROOT/tests/integration/test-db.php" <<< "CREATE DATABASE IF NOT EXISTS \`${TEST_DB}\`;" >/dev/null

cleanup() {
    MYSQL_HOST="$MYSQL_HOST" MYSQL_PORT="$MYSQL_PORT" MYSQL_USER="$MYSQL_USER" MYSQL_PASS="$MYSQL_PASS" MYSQL_DB=mysql \
    php "$REPO_ROOT/tests/integration/test-db.php" <<< "DROP DATABASE IF EXISTS \`${TEST_DB}\`;" >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo "==> MySQL $MYSQL_HOST:$MYSQL_PORT, database $TEST_DB"

# --- Run migrations (first time) ---
rc=0
schema_out=$(DB_HOST="$MYSQL_HOST" DB_PORT="$MYSQL_PORT" DB_USER="$MYSQL_USER" DB_PASS="$MYSQL_PASS" \
    DB_NAME="$TEST_DB" "$PHP_BIN" "$REPO_ROOT/tools/run-migrations.php" 2>&1) || rc=$?
if [ $rc -ne 0 ]; then
    echo "FAIL: migration runner exited $rc:" >&2
    echo "$schema_out" >&2
    exit 1
fi
if ! printf '%s' "$schema_out" | grep -qE 'Applied [0-9]+ migration\(s\)\.'; then
    echo "FAIL: first run did not apply migrations:" >&2
    echo "$schema_out" >&2
    exit 1
fi

# --- Verify expected tables exist ---
for expected_table in users orders service_factors factors schema_migrations; do
    present=$(MYSQL_HOST="$MYSQL_HOST" MYSQL_PORT="$MYSQL_PORT" MYSQL_USER="$MYSQL_USER" MYSQL_PASS="$MYSQL_PASS" MYSQL_DB="$TEST_DB" \
    php "$REPO_ROOT/tests/integration/test-db.php" <<< "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '${TEST_DB}' AND table_name = '${expected_table}';" | tail -1)
    if [ "$present" -lt 1 ]; then
        echo "FAIL: table ${expected_table} missing after migration" >&2
        exit 1
    fi
done

# --- Run migrations again (idempotency) ---
rc=0
schema_out=$(DB_HOST="$MYSQL_HOST" DB_PORT="$MYSQL_PORT" DB_USER="$MYSQL_USER" DB_PASS="$MYSQL_PASS" \
    DB_NAME="$TEST_DB" "$PHP_BIN" "$REPO_ROOT/tools/run-migrations.php" 2>&1) || rc=$?
if [ $rc -ne 0 ]; then
    echo "FAIL: second run migration runner exited $rc:" >&2
    echo "$schema_out" >&2
    exit 1
fi
if ! printf '%s' "$schema_out" | grep -qE 'No new migrations to apply\.'; then
    echo "FAIL: second run was not idempotent:" >&2
    echo "$schema_out" >&2
    exit 1
fi

echo "OK run-migrations_test.sh"
exit 0
