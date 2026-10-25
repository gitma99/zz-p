#!/usr/bin/env bash
# Asserts that the hot-path queries use an index instead of a full table scan.
#
# Creates a throwaway database, applies the real schema (sql/sql.php), seeds
# enough rows for the optimizer to prefer an index, then EXPLAINs each hot
# query and checks the plan.
#
# Usage:
#   MYSQL_HOST=127.0.0.1 MYSQL_USER=root MYSQL_PASS=secret \
#   MYSQL_DB=botpanel_test bash tests/integration/explain-hot-queries.sh
#
# Env:
#   MYSQL_HOST   default 127.0.0.1
#   MYSQL_PORT   default 3306
#   MYSQL_USER   default root
#   MYSQL_PASS   default empty
#   MYSQL_DB     default zzpanel_explain_test  (created, then dropped)
#   PHP_BIN      default php
set -uo pipefail

# Locate the repository root by walking up until sql/sql.php is found, so the
# script works from any checkout layout and from CI's working directory.
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
while [ ! -f "$REPO_ROOT/sql/sql.php" ] && [ "$REPO_ROOT" != "/" ]; do
    REPO_ROOT="$(dirname "$REPO_ROOT")"
done
[ -f "$REPO_ROOT/sql/sql.php" ] || { echo "FAIL: cannot locate sql/sql.php" >&2; exit 1; }
MYSQL_HOST="${MYSQL_HOST:-127.0.0.1}"
MYSQL_PORT="${MYSQL_PORT:-3306}"
MYSQL_USER="${MYSQL_USER:-root}"
MYSQL_PASS="${MYSQL_PASS:-}"
MYSQL_DB="${MYSQL_DB:-zzpanel_explain_test}"
PHP_BIN="${PHP_BIN:-php}"
SEED_USERS=400
SEED_ORDERS=2000

MYSQL_ARGS=(mysql -h "$MYSQL_HOST" -P "$MYSQL_PORT" -u "$MYSQL_USER" --batch)
[ -n "$MYSQL_PASS" ] && MYSQL_ARGS+=(-p"$MYSQL_PASS")

FAILED=0
cleanup() {
    "${MYSQL_ARGS[@]}" -e "DROP DATABASE IF EXISTS \`${MYSQL_DB}\`;" >/dev/null 2>&1 || true
}
trap cleanup EXIT

run_sql() {
    "${MYSQL_ARGS[@]}" "$@" 2>&1
}

echo "==> MySQL $MYSQL_HOST:$MYSQL_PORT, database $MYSQL_DB"

# --- server must be MySQL 8 to exercise the same plans as production ---
version=$(run_sql -N -e "SELECT VERSION();")
echo "    server version: $version"
case "$version" in
    8.*) : ;;
    *) echo "FAIL: expected MySQL 8.x, got: $version" >&2; exit 1 ;;
esac

cleanup
run_sql -e "CREATE DATABASE \`${MYSQL_DB}\`;" >/dev/null || { echo "FAIL: cannot create database" >&2; exit 1; }

# --- apply the real schema, indexes included ---
echo "==> Applying schema from sql/sql.php"
# The PHP snippet is single-quoted on purpose: $argv and $_GET must reach PHP
# unexpanded. Only the repository path is spliced in with double quotes.
# shellcheck disable=SC2016
schema_out=$(cd "$REPO_ROOT" && DB_HOST="$MYSQL_HOST" DB_PORT="$MYSQL_PORT" \
    "$PHP_BIN" -r '
        $_GET["db_username"] = $argv[1];
        $_GET["db_password"] = $argv[2];
        $_GET["db_name"]     = $argv[3];
        include "'"$REPO_ROOT"'/sql/sql.php";
    ' -- "$MYSQL_USER" "$MYSQL_PASS" "$MYSQL_DB" 2>&1)
if ! printf '%s' "$schema_out" | grep -qE '"status"[[:space:]]*:[[:space:]]*true'; then
    echo "FAIL: sql/sql.php did not report success:" >&2
    echo "$schema_out" >&2
    exit 1
fi

# --- seed enough rows that the optimizer has a reason to pick an index ---
# Rows are generated here rather than with a CTE: MySQL 8 wants the WITH clause
# after INSERT INTO, which is not worth the syntax gymnastics for a seed.
echo "==> Seeding $SEED_USERS users / $SEED_ORDERS orders"
{
    i=1
    while [ "$i" -le "$SEED_USERS" ]; do
        echo "INSERT INTO \`users\` (\`from_id\`) VALUES ($((1000 + i)));"
        i=$((i + 1))
    done
    i=1
    while [ "$i" -le "$SEED_ORDERS" ]; do
        echo "INSERT INTO \`orders\` (\`from_id\`,\`location\`,\`protocol\`,\`date\`,\`volume\`,\`link\`,\`price\`,\`code\`,\`status\`,\`type\`) VALUES ($((1001 + i % SEED_USERS)),'panel$((1 + i % 3))','null','2026-01-01','1GB','https://example.invalid/$i','1000','vip$((1 + i % 5))','active','marzban');"
        i=$((i + 1))
    done
} | "${MYSQL_ARGS[@]}" "$MYSQL_DB" >/dev/null || { echo "FAIL: seeding failed" >&2; exit 1; }

"${MYSQL_ARGS[@]}" "$MYSQL_DB" <<'SQL' >/dev/null
INSERT INTO `service_factors` (`from_id`,`location`,`protocol`,`plan`,`price`,`code`,`status`) VALUES (1001,'panel1','null','vip1','1000','vip1','active');
INSERT INTO `factors` (`from_id`,`price`,`code`,`status`) VALUES (1001,'1000','paycode1','no');
SQL

# --- every hot query, with the index it must use ---
# label | SQL | expected index
CHECKS=(
    "users by from_id|SELECT * FROM \`users\` WHERE \`from_id\` = '1001' LIMIT 1|idx_users_from_id"
    "orders by from_id+code|SELECT \`row\`, \`code\`, \`location\` FROM \`orders\` WHERE \`from_id\` = '1001' AND \`code\` = 'vip1'|idx_orders_from_id_code"
    "orders by from_id only|SELECT \`row\`, \`code\` FROM \`orders\` WHERE \`from_id\` = '1001'|idx_orders_from_id_code"
    "service_factors by from_id|SELECT * FROM \`service_factors\` WHERE \`from_id\` = '1001'|idx_service_factors_from_id"
    "factors by code|SELECT * FROM \`factors\` WHERE \`code\` = 'paycode1'|idx_factors_code"
)

echo ""
echo "==> EXPLAIN"
for check in "${CHECKS[@]}"; do
    IFS='|' read -r label query expected <<<"$check"
    plan=$(run_sql "$MYSQL_DB" -e "EXPLAIN ${query};")
    # MySQL 8 EXPLAIN columns: id, select_type, table, partitions, type, key, ...
    key=$(printf '%s' "$plan" | awk -F'\t' 'NR==2 {print $6}')
    type=$(printf '%s' "$plan" | awk -F'\t' 'NR==2 {print $5}')
    if [ "$key" = "$expected" ]; then
        printf '    PASS  %-26s key=%-28s type=%s\n' "$label" "$key" "$type"
    else
        printf '    FAIL  %-26s key=%-28s type=%s (expected %s)\n' "$label" "${key:-NULL}" "${type:-?}" "$expected"
        FAILED=1
    fi
done

# --- the index must actually exist, even if the optimizer chose something else ---
echo ""
echo "==> Index inventory"
missing=""
for spec in "orders idx_orders_from_id_code" "users idx_users_from_id" \
            "service_factors idx_service_factors_from_id" "factors idx_factors_code"; do
    read -r table index <<<"$spec"
    present=$(run_sql -N -e "SELECT COUNT(*) FROM information_schema.statistics
        WHERE table_schema = '${MYSQL_DB}' AND table_name = '${table}' AND index_name = '${index}';")
    if [ "$present" -ge 1 ]; then
        printf '    PASS  %s.%s\n' "$table" "$index"
    else
        printf '    FAIL  %s.%s is missing\n' "$table" "$index"
        missing=1
    fi
done
[ "$missing" = "1" ] && FAILED=1

echo ""
if [ "$FAILED" = "0" ]; then
    echo "RESULT: all hot queries use an index."
    exit 0
fi
echo "RESULT: failures above." >&2
exit 1
