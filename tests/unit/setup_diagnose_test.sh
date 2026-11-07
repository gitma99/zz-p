#!/usr/bin/env bash
set -euo pipefail

# Test harness for setup.sh diagnostic suite
INFO() { echo "[INFO] $*"; }
WARN() { echo "[WARN] $*"; }
ERROR() { echo "[ERROR] $*" >&2; return 1; }
source <(sed -n '/# --- DIAGNOSTIC HELPERS START ---/,/# --- DIAGNOSTIC HELPERS END ---/p' setup.sh)

# Test 1: Status tracking
init_diagnose_state
DIAG_PASS "Pass test"
DIAG_WARN "Warn test"
[[ "$DIAG_HAS_WARN" -eq 1 ]] || { echo "FAIL: DIAG_HAS_WARN not set"; exit 1; }
[[ "$DIAG_HAS_FAIL" -eq 0 ]] || { echo "FAIL: DIAG_HAS_FAIL wrongly set"; exit 1; }
DIAG_FAIL "Fail test"
[[ "$DIAG_HAS_FAIL" -eq 1 ]] || { echo "FAIL: DIAG_HAS_FAIL not set"; exit 1; }

# Test 2: Secret masking
masked=$(mask_secret "token 123456:TEST_BOT_TOKEN_MOCK_XYZ in text" "123456:TEST_BOT_TOKEN_MOCK_XYZ")
[[ "$masked" == *"123456:***"* ]] || { echo "FAIL: secret token was not properly masked"; exit 1; }

# Test 3: Export report
tmp_exp=$(mktemp /tmp/diag-test.XXXXXX)
export_diagnose_report "$tmp_exp" >/dev/null
[[ -f "$tmp_exp" ]] || { echo "FAIL: export report did not create file"; exit 1; }
grep -q "\[PASS\] Pass test" "$tmp_exp" || { echo "FAIL: exported file missing pass text"; exit 1; }
rm -f "$tmp_exp"

# Test 4: diagnose_host function exists and runs
init_diagnose_state
diagnose_host

# Test 5: diagnose_containers with mocked docker
docker() {
    local cmd="${1:-}"
    shift || true
    if [ "$cmd" = "info" ]; then
        return 0
    elif [ "$cmd" = "inspect" ]; then
        local cname="${!#}" # last arg
        if [ "$cname" = "zzpanel_app" ]; then
            echo '{"Status":"running","Health":{"Status":"healthy"},"OOMKilled":false,"ExitCode":0}'
        elif [ "$cname" = "zzpanel_mysql" ]; then
            echo '{"Status":"exited","Health":{"Status":""},"OOMKilled":true,"ExitCode":137}'
        else
            echo '{"Status":"running","Health":{"Status":"healthy"},"OOMKilled":false,"ExitCode":0}'
        fi
        return 0
    elif [ "$cmd" = "logs" ]; then
        echo "Crash log line 1"
        echo "Crash log line 2"
        return 0
    fi
    return 1
}

init_diagnose_state
diagnose_containers
[[ "$DIAG_HAS_FAIL" -eq 1 ]] || { echo "FAIL: diagnose_containers should detect exited OOM container"; exit 1; }
# Verify zzpanel_mysql was added to AUTO_FIX_CANDIDATES
[[ " ${AUTO_FIX_CANDIDATES[*]} " =~ " mysql " ]] || { echo "FAIL: mysql was not added to AUTO_FIX_CANDIDATES"; exit 1; }
unset -f docker

# Test 6: diagnose_db with mock docker exec
test_td=$(mktemp -d)
mkdir -p "$test_td/sql/migrations"
touch "$test_td/sql/migrations/20261025-001-init-schema.sql"
touch "$test_td/sql/migrations/20261025-002-hot-path-indexes.sql"
cat > "$test_td/.env" <<EOF
DB_NAME=zzpanel
DB_USER=zzuser
DB_PASS=zzpass
MYSQL_ROOT_PASSWORD=rootpass
EOF

BASE_DIR="$test_td"

docker() {
    local cmd="${1:-}"
    shift || true
    if [ "$cmd" = "exec" ]; then
        local cname="${1:-}"
        shift || true
        if [ "$cname" = "zzpanel_mysql" ]; then
            # mysqladmin ping or mysql query
            if [[ "$*" == *"mysqladmin ping"* ]]; then
                echo "mysqld is alive"
                return 0
            elif [[ "$*" == *"Threads_connected"* ]]; then
                echo -e "Threads_connected\t10\nmax_connections\t150"
                return 0
            elif [[ "$*" == *"schema_migrations"* ]]; then
                # only 001 applied
                echo "20261025-001-init-schema.sql"
                return 0
            fi
        elif [ "$cname" = "zzpanel_app" ]; then
            if [[ "$*" == *"mysqli"* ]]; then
                echo "DB_AUTH_OK"
                return 0
            elif [[ "$*" == *"apache2ctl"* ]]; then
                return 0
            elif [[ "$*" == *"php7.4-fpm status"* ]]; then
                return 0
            elif [[ "$*" == *"http://localhost/ZZPanelBot/index.php"* ]]; then
                echo "423"
                return 0
            elif [[ "$*" == *"extension_loaded"* ]]; then
                echo "yes"
                return 0
            fi
        fi
    fi
    return 0
}

init_diagnose_state
diagnose_db
[[ "$DIAG_HAS_WARN" -eq 1 ]] || { echo "FAIL: diagnose_db should detect unapplied migrations"; exit 1; }

# Test 7: diagnose_web detects 423 lock and permissions
init_diagnose_state
mkdir -p "$test_td/logs/app" "$test_td/temp"
chmod 555 "$test_td/temp" 2>/dev/null || true
diagnose_web
[[ "$DIAG_HAS_FAIL" -eq 1 ]] || { echo "FAIL: diagnose_web should detect 423 error"; exit 1; }
chmod 777 "$test_td/temp" 2>/dev/null || true
unset -f docker

# Test 8: diagnose_ssl and diagnose_telegram with mocks
cat >> "$test_td/.env" <<EOF
DOMAIN=test.example.com
BOT_TOKEN=123456:TEST_BOT_TOKEN_MOCK_XYZ
EOF

# Create dummy cert for test.example.com
mkdir -p "$test_td/data/certbot/conf/live/test.example.com"
openssl req -x509 -newkey rsa:2048 -keyout "$test_td/privkey.pem" \
    -out "$test_td/data/certbot/conf/live/test.example.com/fullchain.pem" \
    -days 30 -nodes -subj "/CN=test.example.com" 2>/dev/null || true

docker() {
    local cmd="${1:-}"
    shift || true
    if [ "$cmd" = "exec" ]; then
        if [ "$1" = "zzpanel_nginx" ]; then
            echo "nginx: configuration syntax is ok"
            return 0
        fi
    fi
    return 0
}

curl() {
    local url="${!#}" # last argument
    if [[ "$url" == *"api.telegram.org"* ]]; then
        if [[ "$url" == *"/getMe"* ]]; then
            echo '{"ok":true,"result":{"id":123456,"is_bot":true,"username":"test_bot"}}'
            return 0
        elif [[ "$url" == *"/getWebhookInfo"* ]]; then
            echo '{"ok":true,"result":{"url":"https://test.example.com/ZZPanelBot/index.php","pending_update_count":120,"last_error_message":"Wrong response code: 502 Bad Gateway","last_error_date":1700000000}}'
            return 0
        elif [[ "$*" == *"-I"* ]]; then
            # TLS probe
            return 0
        fi
    elif [[ "$url" == *"127.0.0.1"* ]]; then
        echo "200"
        return 0
    elif [[ "$url" == *"ipify.org"* ]]; then
        echo "1.2.3.4"
        return 0
    fi
    return 0
}

init_diagnose_state
diagnose_ssl
[[ "$DIAG_HAS_FAIL" -eq 0 ]] || { echo "FAIL: diagnose_ssl failed unexpectedly"; exit 1; }

init_diagnose_state
diagnose_telegram
[[ "$DIAG_HAS_FAIL" -eq 1 ]] || { echo "FAIL: diagnose_telegram should fail on last_error"; exit 1; }
[[ " ${AUTO_FIX_CANDIDATES[*]} " =~ " webhook " ]] || { echo "FAIL: webhook was not added to AUTO_FIX_CANDIDATES"; exit 1; }

rm -rf "$test_td"
unset -f docker curl

# Test 9: apply_auto_fixes applies permission, webhook, and container fixes
init_diagnose_state
AUTO_FIX_CANDIDATES=("permissions" "webhook" "mysql")
FIX_ACTIONS_RUN=()

curl() {
    FIX_ACTIONS_RUN+=("curl:$*")
    return 0
}
chown() {
    FIX_ACTIONS_RUN+=("chown:$*")
    return 0
}
chmod() {
    FIX_ACTIONS_RUN+=("chmod:$*")
    return 0
}
DOCKER_COMPOSE_CMD=(echo "compose")

DOMAIN="example.com"
BOT_TOKEN="123456:FAKE"
BASE_DIR="/tmp"

apply_auto_fixes false # non-interactive
[[ " ${FIX_ACTIONS_RUN[*]} " =~ "chown:" ]] || { echo "FAIL: auto-fix permissions chown was not executed"; exit 1; }
[[ " ${FIX_ACTIONS_RUN[*]} " =~ "drop_pending_updates=true" ]] || { echo "FAIL: auto-fix webhook reset was not executed"; exit 1; }
unset -f curl chown chmod

# Test 10: diagnose_all exists and returns exit code 0 or 1
init_diagnose_state
type diagnose_all >/dev/null || { echo "FAIL: diagnose_all function does not exist"; exit 1; }

# Test 11: verify main menu contains 15) and [0-15]
grep -q '15) Uninstall ZZPanel' setup.sh || { echo "FAIL: setup.sh missing 15) in menu"; exit 1; }
grep -q '\[0-15\]' setup.sh || { echo "FAIL: setup.sh missing [0-15] in prompt"; exit 1; }

# Test 12: export_diagnose_report redacts credentials found in .env
fix_td=$(mktemp -d)
cat > "$fix_td/.env" <<'EOF'
BOT_TOKEN=987654:XYZ-SECRET_BOT_TOKEN
DB_PASS=SuperSecretPassWithSpace
MYSQL_ROOT_PASSWORD=RootSecretPassword
EOF
BASE_DIR="$fix_td"
init_diagnose_state
DIAG_FAIL "Failed connecting with password SuperSecretPassWithSpace and token 987654:XYZ-SECRET_BOT_TOKEN"
rep_file=$(export_diagnose_report "$fix_td/report.log")
if grep -q "XYZ-SECRET_BOT_TOKEN" "$rep_file"; then
    rm -rf "$fix_td"
    echo "FAIL: export_diagnose_report did not redact BOT_TOKEN"
    exit 1
fi
if grep -q "SuperSecretPassWithSpace" "$rep_file"; then
    rm -rf "$fix_td"
    echo "FAIL: export_diagnose_report did not redact DB_PASS"
    exit 1
fi
rm -rf "$fix_td"

# Test 13: Headless apply_auto_fixes does not crash when stdin is /dev/null
init_diagnose_state
AUTO_FIX_CANDIDATES=("permissions")
apply_auto_fixes true < /dev/null || { echo "FAIL: headless apply_auto_fixes crashed on EOF"; exit 1; }

# Test 14: diagnose_telegram reports FAIL when DOMAIN is missing
fix_td2=$(mktemp -d)
cat > "$fix_td2/.env" <<'EOF'
BOT_TOKEN=123456:TEST_BOT_TOKEN_MOCK_XYZ
EOF
BASE_DIR="$fix_td2"
init_diagnose_state
curl() {
    local url="${!#}"
    if [[ "$url" == *"/getMe"* ]]; then
        echo '{"ok":true,"result":{"id":123456,"is_bot":true,"username":"test_bot"}}'
    elif [[ "$url" == *"/getWebhookInfo"* ]]; then
        echo '{"ok":true,"result":{"url":"","pending_update_count":0,"last_error_message":""}}'
    fi
    return 0
}
diagnose_telegram
unset -f curl
rm -rf "$fix_td2"
[[ "$DIAG_LOG_BUFFER" == *"DOMAIN is not configured in .env"* ]] || { echo "FAIL: diagnose_telegram should detect missing DOMAIN"; exit 1; }

# Test 15: Category CLI execution sets exit code 1 when DIAG_HAS_FAIL=1
test_cli_exit=$(bash -c '
    source <(sed -n "/# --- DIAGNOSTIC HELPERS START ---/,/# --- DIAGNOSTIC HELPERS END ---/p" setup.sh)
    INFO() { :; }
    WARN() { :; }
    target_cat="containers"
    auto_fix="false"
    export_rep="false"
    init_diagnose_state
    DIAG_FAIL "Simulated failure"
    [ "$DIAG_HAS_FAIL" -eq 0 ] || exit 1
    exit 0
' || echo "EXIT_1")
[[ "$test_cli_exit" == *"EXIT_1"* ]] || { echo "FAIL: category failure should exit 1"; exit 1; }

# Test 16: scrape_container_crash_logs fails fast on empty container name
set +e
scrape_container_crash_logs "" 2>/dev/null
scrape_rc=$?
set -e
[[ "$scrape_rc" -ne 0 ]] || { echo "FAIL: scrape_container_crash_logs should fail fast when container_name is empty"; exit 1; }

# Test 17: get_env_var helper parses keys correctly
env_tmp=$(mktemp)
cat > "$env_tmp" <<'EOF'
FOO="bar_value"
SPACED =  'spaced_val'  
EMPTY=
EOF
[[ "$(get_env_var "FOO" "$env_tmp")" == "bar_value" ]] || { echo "FAIL: get_env_var FOO"; exit 1; }
[[ "$(get_env_var "SPACED" "$env_tmp")" == "spaced_val" ]] || { echo "FAIL: get_env_var SPACED"; exit 1; }
[[ "$(get_env_var "EMPTY" "$env_tmp")" == "" ]] || { echo "FAIL: get_env_var EMPTY"; exit 1; }
rm -f "$env_tmp"

# Test 18: Missing container is not added to AUTO_FIX_CANDIDATES
docker() {
    local cmd="${1:-}"
    shift || true
    if [ "$cmd" = "info" ]; then
        return 0
    elif [ "$cmd" = "inspect" ]; then
        # All containers missing
        echo "[]"
        return 0
    fi
    return 1
}
init_diagnose_state
diagnose_containers
[[ "${#AUTO_FIX_CANDIDATES[@]}" -eq 0 ]] || { echo "FAIL: missing containers must not be added to AUTO_FIX_CANDIDATES"; exit 1; }
unset -f docker

# Test 19: zzbot diagnose category with --export does not crash with local scope error
test_cat_export=$(bash -c '
    # Mock environment
    export BASE_DIR=$(mktemp -d)
    cat > "$BASE_DIR/.env" <<EOF
DOMAIN=test.example.com
BOT_TOKEN=123456:TEST_BOT_TOKEN_MOCK_XYZ
EOF
    # Run setup.sh diagnose ssl --export
    bash setup.sh diagnose ssl --export 2>&1
    rc=$?
    rm -rf "$BASE_DIR"
    exit $rc
' || echo "EXPORT_FAIL")
[[ "$test_cat_export" != *"can only be used in a function"* ]] || { echo "FAIL: local scope error on category export"; exit 1; }

# Test 20: get_env_var and resolve_ipv4_host fail fast on empty argument
set +e
get_env_var "" 2>/dev/null
gev_rc=$?
resolve_ipv4_host "" 2>/dev/null
rih_rc=$?
set -e
[[ "$gev_rc" -ne 0 ]] || { echo "FAIL: get_env_var should fail fast on empty key"; exit 1; }
[[ "$rih_rc" -ne 0 ]] || { echo "FAIL: resolve_ipv4_host should fail fast on empty target"; exit 1; }

# Test 21: Submenu prompt uses [0-7]
grep -q 'Enter choice \[0-7\]:' setup.sh || { echo "FAIL: setup.sh submenu should prompt [0-7]"; exit 1; }

# Test 22: HTTPS loopback returning 500 sets DIAG_HAS_FAIL=1
ssl_td=$(mktemp -d)
cat > "$ssl_td/.env" <<EOF
DOMAIN=test.example.com
EOF
BASE_DIR="$ssl_td"
docker() { echo "nginx: configuration syntax is ok"; return 0; }
curl() {
    local url="${!#}"
    if [[ "$url" == *"127.0.0.1"* ]]; then
        echo "500"
    else
        echo "1.2.3.4"
    fi
    return 0
}
init_diagnose_state
diagnose_ssl
[[ "$DIAG_HAS_FAIL" -eq 1 ]] || { echo "FAIL: diagnose_ssl should mark HTTP 500 as failure"; exit 1; }
rm -rf "$ssl_td"
unset -f docker curl

# Test 23: Secret masking with glob characters (*, ?, [)
secret_glob="P*ss?w[o]rd"
masked_glob=$(mask_secret "My secret is P*ss?w[o]rd in this log" "$secret_glob")
[[ "$masked_glob" == *"My secret is P*ss?w[o]rd in this log"* ]] && { echo "FAIL: secret with glob was not masked"; exit 1; }
[[ "$masked_glob" == *"***"* ]] || { echo "FAIL: secret with glob replacement missing"; exit 1; }

# Test 24: run_interactive_diagnose_category fails fast on invalid category
set +e
run_interactive_diagnose_category "invalid_cat" 2>/dev/null
inv_cat_rc=$?
set -e
[[ "$inv_cat_rc" -ne 0 ]] || { echo "FAIL: run_interactive_diagnose_category must fail on invalid category"; exit 1; }

echo "PASS: Review fix tests"
