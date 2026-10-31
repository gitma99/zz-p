#!/usr/bin/env bash
set -euo pipefail

# Test harness for setup.sh diagnostic suite
INFO() { echo "[INFO] $*"; }
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
masked=$(mask_secret "token 123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11 in text" "123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11")
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
BOT_TOKEN=123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11
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
            echo '{"ok":true,"result":{"url":"https://test.example.com/ZZPanelBot/index.php","pending_update_count":120,"last_error_message":"Wrong response code: 502 Bad Gateway"}}'
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
[[ "$DIAG_HAS_WARN" -eq 1 ]] || { echo "FAIL: diagnose_telegram should warn on high backlog / last_error"; exit 1; }
[[ " ${AUTO_FIX_CANDIDATES[*]} " =~ " webhook " ]] || { echo "FAIL: webhook was not added to AUTO_FIX_CANDIDATES"; exit 1; }

rm -rf "$test_td"
unset -f docker curl

echo "PASS: Task 4 tests"
