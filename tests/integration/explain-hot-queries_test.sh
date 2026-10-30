#!/usr/bin/env bash
# Integration test: asserts that the hot-path queries use an index instead of a full table scan.
#
# Creates a throwaway database, applies the versioned schema migrations, seeds
# enough rows for the optimizer to prefer an index, then EXPLAINs each hot
# query and checks the plan.
#
# Usage:
#   MYSQL_HOST=127.0.0.1 MYSQL_USER=root MYSQL_PASS=secret \\
#   MYSQL_DB=botpanel_test bash tests/integration/explain-hot-queries_test.sh
#
# Env:
#   MYSQL_HOST   default 127.0.0.1
#   MYSQL_PORT   default 3306
#   MYSQL_USER   default root
#   MYSQL_PASS   default empty
#   MYSQL_DB     ignored — always uses zzpanel_explain_$$ (unique per run)
#   PHP_BIN      default php
set -euo pipefail

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
MYSQL_DB="zzpanel_explain_$$"
PHP_BIN="${PHP_BIN:-php}"
SEED_USERS=400
SEED_ORDERS=2000

# --- DB helper: run SQL via PHP mysqli (no mysql CLI dependency) ---
run_sql() {
    local db=""
    local query=""

    while [ $# -gt 0 ]; do
        case "$1" in
            -e) shift; query="$1"; shift ;;
            -N) shift ;;
            -*) shift ;;
            *)
                if [ -z "$query" ]; then
                    db="$1"
                else
                    query="$1"
                fi
                shift ;;
        esac
    done

    if [ -z "$query" ]; then
        query=$(cat)
    fi

    if [ -n "$db" ]; then
        MYSQL_HOST="$MYSQL_HOST" MYSQL_PORT="$MYSQL_PORT" MYSQL_USER="$MYSQL_USER" MYSQL_PASS="$MYSQL_PASS" MYSQL_DB="$db" \
        php "$REPO_ROOT/tests/integration/test-db.php" <<< "$query"
    else
        # No database specified — connect to the system 'mysql' database so
        # server-level commands (CREATE/DROP DATABASE, SELECT VERSION()) work
        # before the test database exists.
        MYSQL_HOST="$MYSQL_HOST" MYSQL_PORT="$MYSQL_PORT" MYSQL_USER="$MYSQL_USER" MYSQL_PASS="$MYSQL_PASS" MYSQL_DB=mysql \
        php "$REPO_ROOT/tests/integration/test-db.php" <<< "$query"
    fi
}

FAILED=0
cleanup() {
    run_sql -e "DROP DATABASE IF EXISTS ${MYSQL_DB};" >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo "==> MySQL $MYSQL_HOST:$MYSQL_PORT, database $MYSQL_DB"

# --- server must be MySQL 8 to exercise the same plans as production ---
version=$(run_sql -N -e "SELECT VERSION();")
echo "    server version: $version"
case "$version" in
    8.*) : ;;
    *) echo "FAIL: expected MySQL 8.x, got: $version" >&2; exit 1 ;;
esac

# --- create the database ---
run_sql -e "CREATE DATABASE IF NOT EXISTS ${MYSQL_DB};" >/dev/null

echo "==> Applying schema migrations from sql/migrations/"
rc=0
schema_out=$(DB_HOST="$MYSQL_HOST" DB_PORT="$MYSQL_PORT" DB_USER="$MYSQL_USER" DB_PASS="$MYSQL_PASS" \
    DB_NAME="$MYSQL_DB" "$PHP_BIN" "$REPO_ROOT/tools/run-migrations.php" 2>&1) || rc=$?
if [ $rc -ne 0 ]; then
    echo "FAIL: migration runner exited $rc:" >&2
    echo "$schema_out" >&2
    exit 1
fi
if ! printf '%s' "$schema_out" | grep -qE 'Applied [0-9]+ migration\(s\)\.|No new migrations to apply\.'; then
    echo "FAIL: migrations did not report a clean apply:" >&2
    echo "$schema_out" >&2
    exit 1
fi

# --- seed enough rows that the optimizer has a reason to pick an index ---
echo "==> Seeding $SEED_USERS users / $SEED_ORDERS orders"
{
    # users: bulk INSERT in batches of 500 to stay under max_allowed_packet
    batch=()
    i=1
    while [ "$i" -le "$SEED_USERS" ]; do
        batch+=("($((1000 + i)))")
        if [ "${#batch[@]}" -ge 500 ] || [ "$i" -eq "$SEED_USERS" ]; then
            echo "INSERT INTO users (from_id) VALUES $(IFS=','; echo "${batch[*]}");"
            batch=()
        fi
        i=$((i + 1))
    done

    # orders: bulk INSERT in batches of 500 to stay under max_allowed_packet
    batch=()
    i=1
    while [ "$i" -le "$SEED_ORDERS" ]; do
        batch+=("($((1001 + i % SEED_USERS)),'panel$((1 + i % 3))','null','2026-01-01','1GB','https://example.invalid/$i','1000','vip$((1 + i % 5))','active','marzban')")
        if [ "${#batch[@]}" -ge 500 ] || [ "$i" -eq "$SEED_ORDERS" ]; then
            echo "INSERT INTO orders (from_id, location, protocol, date, volume, link, price, code, status, type) VALUES $(IFS=','; echo "${batch[*]}");"
            batch=()
        fi
        i=$((i + 1))
    done
} | run_sql "$MYSQL_DB" >/dev/null || { echo "FAIL: seeding failed" >&2; exit 1; }

run_sql "$MYSQL_DB" <<'SQL' >/dev/null || { echo "FAIL: seeding service_factors/factors failed" >&2; exit 1; }
INSERT INTO service_factors (from_id, location, protocol, plan, price, code, status) VALUES (1001,'panel1','null','vip1','1000','vip1','active');
INSERT INTO factors (from_id, price, code, status) VALUES (1001,'1000','paycode1','no');
SQL

# --- every hot query, with the index it must use ---
echo ""
echo "==> EXPLAIN"
CHECKS=(
    "users by from_id|SELECT * FROM users WHERE from_id = '1001' LIMIT 1|idx_users_from_id"
    "orders by from_id+code|SELECT \`row\`, code, location FROM orders WHERE from_id = '1001' AND code = 'vip1'|idx_orders_from_id_code"
    "orders by from_id only|SELECT \`row\`, code FROM orders WHERE from_id = '1001'|idx_orders_from_id_code"
    "service_factors by from_id|SELECT * FROM service_factors WHERE from_id = '1001'|idx_service_factors_from_id"
    "factors by code|SELECT * FROM factors WHERE code = 'paycode1'|idx_factors_code"
)
for check in "${CHECKS[@]}"; do
    IFS='|' read -r label query expected <<<"$check"
    plan=$(run_sql "$MYSQL_DB" -e "EXPLAIN ${query};")
    type=$(printf '%s' "$plan" | awk -F'\t' 'NR==1 {print $5}')
    key=$(printf '%s' "$plan" | awk -F'\t' 'NR==1 {print $7}')
    rows=$(printf '%s' "$plan" | awk -F'\t' 'NR==1 {print $10}')
    extra=$(printf '%s' "$plan" | awk -F'\t' 'NR==1 {print $12}')

    ok=1
    if [ "$key" != "$expected" ]; then
        printf '    FAIL  %-26s key=%-28s (expected %s)\n' "$label" "${key:-NULL}" "$expected"
        ok=0
    fi
    if [ "$type" = "ALL" ]; then
        printf '    FAIL  %-26s type=ALL (full table scan)\n' "$label"
        ok=0
    fi
    case "$extra" in
        *Using\ filesort*|*Using\ temporary*)
            printf '    FAIL  %-26s extra=%s (filesort/temporary)\n' "$label" "$extra"
            ok=0 ;;
    esac
    if [ "$ok" -eq 1 ]; then
        printf '    PASS  %-26s key=%-28s type=%s rows=%s\n' "$label" "$key" "$type" "${rows:-?}"
    else
        FAILED=1
    fi
done

# --- the index must actually exist, even if the optimizer chose something else ---
echo ""
echo "==> Index inventory"
missing=""
for spec in "orders idx_orders_from_id_code" "users idx_users_from_id" \
            "service_factors idx_service_factors_from_id" "factors idx_factors_code" \
            "task_queue idx_status_id"; do
    read -r table index <<<"$spec"
    present=$(run_sql -N -e "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = '${MYSQL_DB}' AND table_name = '${table}' AND index_name = '${index}';")
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
