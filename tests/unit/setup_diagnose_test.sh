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

echo "PASS: Task 2 tests"
