#!/usr/bin/env bash
# Unit tests for merge_env_files (setup.sh) — merges new vars from
# .env.example into .env during update. Follows the setup_diagnose_test.sh
# pattern: stub the helpers, source just the marker-wrapped section.
set -euo pipefail

INFO() { echo "[INFO] $*"; }
WARN() { echo "[WARN] $*"; }
ERROR() { echo "[ERROR] $*" >&2; return 1; }

# Extract the ENV MERGE section from setup.sh (repo root = this file's ../..)
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
source <(sed -n '/# --- ENV MERGE START ---/,/# --- ENV MERGE END ---/p' "$REPO_ROOT/setup.sh")

# Guard against vacuous passes: if the section didn't source, every
# "not added" assertion would pass trivially.
type merge_env_files >/dev/null 2>&1 || { echo "FAIL: merge_env_files not found in setup.sh ENV MERGE section"; exit 1; }

PASS_COUNT=0
FAIL_COUNT=0
run_test() {
    local name="$1"; shift
    local td
    td=$(mktemp -d)
    # Subshell cd's into $td so relative .env paths land there, and BASE_DIR
    # points at the same place the function reads from.
    if ( cd "$td" && BASE_DIR="$td"; "$@" ); then
        PASS_COUNT=$((PASS_COUNT + 1))
        echo "PASS $name"
    else
        FAIL_COUNT=$((FAIL_COUNT + 1))
        echo "FAIL $name"
    fi
    rm -rf "$td"
}

# Case 1: adds active new var, keeps existing
test_adds_active_new_var() {
    echo "BOT_TOKEN=mine" > .env
    printf 'BOT_TOKEN=example\nNEW_VAR=1\n' > .env.example
    merge_env_files
    grep -q "^NEW_VAR=1$" .env
    grep -q "^BOT_TOKEN=mine$" .env
}

# Case 2: adds commented new var
test_adds_commented_new_var() {
    echo "BOT_TOKEN=mine" > .env
    printf 'BOT_TOKEN=example\n# NEW_OPT=default\n' > .env.example
    merge_env_files
    grep -q "^# NEW_OPT=default$" .env
}

# Case 3: commented key in .env blocks both forms
test_commented_key_blocks_both_forms() {
    echo "# LOG_LEVEL=INFO" > .env
    printf 'LOG_LEVEL=WARNING\n' > .env.example
    cp .env .env.before
    merge_env_files
    cmp -s .env .env.before
}

# Case 4: active key in .env blocks example's commented form
test_active_key_blocks_commented_form() {
    echo "BACKUP_RETENTION_DAYS=7" > .env
    printf '# BACKUP_RETENTION_DAYS=30\n' > .env.example
    cp .env .env.before
    merge_env_files
    cmp -s .env .env.before
}

# Case 5: identical key sets -> byte-identical, no merged comment
test_no_change_no_merged_comment() {
    printf 'A=1\n# B=2\n' > .env
    cp .env .env.example
    merge_env_files
    cmp -s .env .env.example
    ! grep -q "Merged from .env.example" .env
}

# Case 6: dated comment above added block
test_dated_comment_above_added_block() {
    echo "BOT_TOKEN=mine" > .env
    printf 'BOT_TOKEN=example\nNEW_VAR=1\n' > .env.example
    merge_env_files
    local n
    n=$(grep -n "^NEW_VAR=1$" .env | cut -d: -f1)
    head -n $((n - 1)) .env | tail -n 1 | grep -Eq '^# Merged from \.env\.example on [0-9]{4}-[0-9]{2}-[0-9]{2}: .*$'
}

# Case 7: idempotency
test_idempotency() {
    echo "BOT_TOKEN=mine" > .env
    printf 'BOT_TOKEN=example\nNEW_VAR=1\n' > .env.example
    merge_env_files
    cp .env .env.after_first
    merge_env_files
    cmp -s .env .env.after_first
    [ "$(grep -c "Merged from .env.example" .env)" -eq 1 ]
}

# Case 8: value containing '=' appended verbatim
test_value_with_equals() {
    echo "BOT_TOKEN=mine" > .env
    printf 'WEBHOOK_BASE=https://x.com/a?b=c\n' > .env.example
    merge_env_files
    grep -q "^WEBHOOK_BASE=https://x.com/a?b=c$" .env
}

# Case 9: missing example -> byte-identical, exit 0
test_missing_example() {
    echo "BOT_TOKEN=mine" > .env
    cp .env .env.before
    merge_env_files
    cmp -s .env .env.before
}

# Case 10: prefix collision is not a match
test_prefix_collision_not_a_match() {
    echo "LOG_LEVEL_MAX=100" > .env
    printf 'LOG_LEVEL=WARNING\n' > .env.example
    merge_env_files
    grep -q "^LOG_LEVEL=WARNING$" .env
}

run_test "1 adds active new var" test_adds_active_new_var
run_test "2 adds commented new var" test_adds_commented_new_var
run_test "3 commented key blocks both forms" test_commented_key_blocks_both_forms
run_test "4 active key blocks commented form" test_active_key_blocks_commented_form
run_test "5 no change no merged comment" test_no_change_no_merged_comment
run_test "6 dated comment above added block" test_dated_comment_above_added_block
run_test "7 idempotency" test_idempotency
run_test "8 value with equals" test_value_with_equals
run_test "9 missing example" test_missing_example
run_test "10 prefix collision not a match" test_prefix_collision_not_a_match

echo
echo "Env merge tests: $PASS_COUNT passed, $FAIL_COUNT failed"
[ "$FAIL_COUNT" -eq 0 ]
