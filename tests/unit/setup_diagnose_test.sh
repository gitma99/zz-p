#!/usr/bin/env bash
set -euo pipefail

# Test harness for setup.sh diagnostic suite
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

echo "PASS: Task 1 tests"
