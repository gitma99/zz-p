#!/usr/bin/env bash
# Unit test: invariants of the infra fixes that no PHP unit test can reach.
# Each check is a regression guard for a specific commit — see the message.
set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO_ROOT" || exit 1

fail() { echo "FAIL: $1"; exit 1; }

# must_not_match <file...> -- <pattern> <message>
must_not_match() {
    local files=()
    while [ "$1" != "--" ]; do files+=("$1"); shift; done
    shift
    local pattern="$1" msg="$2"
    if grep -n -- "$pattern" "${files[@]}" 2>/dev/null; then
        fail "$msg"
    fi
}

# must_match <file> <pattern> <message>
must_match() {
    grep -q -- "$2" "$1" 2>/dev/null || fail "$3"
}

# must_match_fixed <file> <literal> <message>
must_match_fixed() {
    grep -Fq -- "$2" "$1" 2>/dev/null || fail "$3"
}

# --- 1. MySQL healthcheck must authenticate ---------------------------------
# `mysqladmin ping` exits 0 even on "Access denied", so it cannot gate
# depends_on: service_healthy. Use a query that fails closed.
must_not_match docker-compose.yml docker-compose.build.yml docker-compose.test.yml \
    .github/workflows/ci.yml -- "mysqladmin ping" \
    "mysql healthcheck uses mysqladmin ping (exits 0 on Access denied)"

for f in docker-compose.yml docker-compose.build.yml; do
    must_match "$f" "SELECT 1" "$f: mysql healthcheck is not an authenticated query"
    must_match_fixed "$f" '-p\"$$MYSQL_ROOT_PASSWORD\"' "$f: mysql healthcheck password must stay quoted (word-splitting)"
done

# --- 2. sql/sql.php must stay CLI-only --------------------------------------
# It used to accept DB credentials over HTTP ($_GET); that path is removed.
must_not_match sql/sql.php -- "\$_GET\['db_" "sql/sql.php accepts DB credentials over HTTP again"
must_match sql/sql.php "php_sapi_name() !== 'cli'" "sql/sql.php lost its CLI-only guard"
must_match sql/sql.php "http_response_code(410)" "sql/sql.php no longer answers HTTP with 410"

# --- 3. The image must not ship .env ----------------------------------------
must_match .dockerignore ".env" ".dockerignore no longer excludes .env"

# --- 4. Telegram API calls must verify TLS ----------------------------------
must_not_match App/apis/telegram-api/utils/api_utils.php -- "CURLOPT_SSL_VERIFYPEER" \
    "telegram bot() disables TLS verification again"

# --- 5. Restore: import in the backup image, migrate in the app container ---
# The backup image has no PHP, so restore.sh must not call php at all.
must_not_match docker/backup/restore.sh -- "php" \
    "docker/backup/restore.sh calls php, but the backup image ships no PHP"
must_match docker/backup/restore.sh "mysql -h" "restore.sh no longer imports the dump"

restore_line=$(grep -n 'docker exec -it zzpanel_backup /restore.sh' docker/scripts/docker_backup.sh | cut -d: -f1)
migrate_line=$(grep -n 'run-migrations.php' docker/scripts/docker_backup.sh | head -1 | cut -d: -f1)
[ -n "$restore_line" ] || fail "docker_backup.sh no longer runs restore.sh"
[ -n "$migrate_line" ] || fail "docker_backup.sh no longer runs schema migrations after restore"
[ "$migrate_line" -gt "$restore_line" ] || fail "docker_backup.sh runs migrations before the import"
must_match docker/scripts/docker_backup.sh "WARNING: schema migrations did not run" \
    "a failed migration must warn, not abort a completed restore"

# --- 6. update.sh must not hand out 777 -------------------------------------
must_not_match update.sh -- "chmod -R 777" "update.sh applies chmod -R 777 again"
must_match_fixed update.sh 'chmod 755 "${APP_DIR}/Scripts/"*.sh' "update.sh no longer restores the exec bit on Scripts/*.sh"
must_match update.sh 'chmod 600 "$BOT_CONFIG_JSON_PATH"' "update.sh no longer locks down bot_config.json"

# --- 7. Timezone has a single source ----------------------------------------
must_not_match index.php send.php -- 'date_default_timezone_set("UTC")' \
    "an entrypoint overrides the timezone back to UTC"
must_match config.php "date_default_timezone_set('Asia/Tehran')" "config.php is no longer the timezone source"

# --- 8. Inline callback/handler regressions (no unit-testable seam) ---------
# Lock-list callbacks must use "|" : "-" and "_" both occur inside chat ids.
must_match index.php 'remove_lock|' "lock-list callback no longer uses the | separator"
must_not_match index.php -- 'remove_lock[-_]' "lock-list callback uses a separator that collides with chat ids"

# Account status: the orders lookup must filter by the parsed owner id, not
# the caller, or an admin toggles a service belonging to someone else.
# (The earlier probe at the top of change() intentionally filters by caller.)
must_match custom.php "AND \`from_id\` = '\$config_user_id'" "account-status lookup lost the parsed owner id"

# Kicked members must fail the force-join gate, same as "left".
must_match config.php "in_array(\$status, array('left', 'kicked'), true)" "force-join treats kicked members as joined"

# The send queue may only be wiped after send.php answered 200.
gate_line=$(grep -n 'http_code === 200' index.php | cut -d: -f1)
[ -n "$gate_line" ] || fail "broadcast lost its HTTP 200 gate"
sed -n "${gate_line},$((gate_line + 3))p" index.php | grep -q 'DELETE from `sends`' \
    || fail "broadcast wipes the sends queue outside the HTTP 200 branch"

echo "OK infra_invariants_test.sh"
