#!/usr/bin/env bash
# Integration test: sql/sql.php CLI happy path against a real MySQL.
# install.sh and docker-entrypoint.sh run this file and parse .status, so a
# wrong exit code or JSON shape breaks installs silently.
#
# Usage:
#   MYSQL_HOST=127.0.0.1 MYSQL_USER=root MYSQL_PASS=secret \
#   bash tests/integration/sql_cli_db_test.sh
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
TEST_DB="zzpanel_sql_cli_test_$$"
PHP_BIN="${PHP_BIN:-php}"

# sql/sql.php does not read DB_PORT; keep the harness honest.
if [ "$MYSQL_PORT" != "3306" ]; then
    echo "SKIP sql_cli_db_test.sh (sql.php has no DB_PORT support, harness uses $MYSQL_PORT)"
    exit 0
fi

MYSQL_HOST="$MYSQL_HOST" MYSQL_PORT="$MYSQL_PORT" MYSQL_USER="$MYSQL_USER" MYSQL_PASS="$MYSQL_PASS" MYSQL_DB=mysql \
php "$REPO_ROOT/tests/integration/test-db.php" <<< "CREATE DATABASE IF NOT EXISTS \`${TEST_DB}\`;" >/dev/null

cleanup() {
    MYSQL_HOST="$MYSQL_HOST" MYSQL_PORT="$MYSQL_PORT" MYSQL_USER="$MYSQL_USER" MYSQL_PASS="$MYSQL_PASS" MYSQL_DB=mysql \
    php "$REPO_ROOT/tests/integration/test-db.php" <<< "DROP DATABASE IF EXISTS \`${TEST_DB}\`;" >/dev/null 2>&1 || true
}
trap cleanup EXIT

run_sql_php() {
    DB_HOST="$MYSQL_HOST" DB_USER="$MYSQL_USER" DB_PASS="$MYSQL_PASS" DB_NAME="$TEST_DB" \
    "$PHP_BIN" "$REPO_ROOT/sql/sql.php"
}

echo "==> MySQL $MYSQL_HOST:$MYSQL_PORT, database $TEST_DB"

# 1. First run: exit 0 and status true.
rc=0
out=$(run_sql_php 2>&1) || rc=$?
[ "$rc" -eq 0 ] || { echo "FAIL: sql.php exited $rc: $out" >&2; exit 1; }
printf '%s' "$out" | tr -d '[:space:]' | grep -q '"status":true' || { echo "FAIL: sql.php did not report status true: $out" >&2; exit 1; }

# 2. It created the migration tracker table it promises.
tables=$(MYSQL_HOST="$MYSQL_HOST" MYSQL_PORT="$MYSQL_PORT" MYSQL_USER="$MYSQL_USER" MYSQL_PASS="$MYSQL_PASS" MYSQL_DB="$TEST_DB" \
    php "$REPO_ROOT/tests/integration/test-db.php" <<< "SHOW TABLES LIKE 'schema_migrations';")
printf '%s' "$tables" | grep -q 'schema_migrations' || { echo "FAIL: schema_migrations table missing: $tables" >&2; exit 1; }

# 3. Idempotent: a second run (every container boot) still exits 0.
rc=0
out=$(run_sql_php 2>&1) || rc=$?
[ "$rc" -eq 0 ] || { echo "FAIL: second sql.php run exited $rc: $out" >&2; exit 1; }
printf '%s' "$out" | tr -d '[:space:]' | grep -q '"status":true' || { echo "FAIL: second run did not report status true: $out" >&2; exit 1; }

echo "OK sql_cli_db_test.sh"
