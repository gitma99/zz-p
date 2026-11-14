#!/usr/bin/env bash
# Unit tests for merge_env_files (setup.sh) — merges new vars from
# .env.example into .env during update. Follows the setup_diagnose_test.sh
# pattern: stub the helpers, source just the marker-wrapped section.
#
# Every assertion ends with "|| return 1" (or a named rc capture): the test
# functions run in a subshell inside run_test's `if`, where errexit is
# suppressed, so a failed assertion would otherwise be silently skipped and
# a later passing command would make the test pass vacuously.
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
    grep -q "^NEW_VAR=1$" .env || return 1
    grep -q "^BOT_TOKEN=mine$" .env || return 1
}

# Case 2: adds commented new var
test_adds_commented_new_var() {
    echo "BOT_TOKEN=mine" > .env
    printf 'BOT_TOKEN=example\n# NEW_OPT=default\n' > .env.example
    merge_env_files
    grep -q "^# NEW_OPT=default$" .env || return 1
}

# Case 3: commented key in .env blocks both forms
test_commented_key_blocks_both_forms() {
    echo "# LOG_LEVEL=INFO" > .env
    printf 'LOG_LEVEL=WARNING\n' > .env.example
    cp .env .env.before
    merge_env_files
    cmp -s .env .env.before || return 1
}

# Case 4: active key in .env blocks example's commented form
test_active_key_blocks_commented_form() {
    echo "BACKUP_RETENTION_DAYS=7" > .env
    printf '# BACKUP_RETENTION_DAYS=30\n' > .env.example
    cp .env .env.before
    merge_env_files
    cmp -s .env .env.before || return 1
}

# Case 5: identical key sets -> byte-identical, no merged comment
test_no_change_no_merged_comment() {
    printf 'A=1\n# B=2\n' > .env
    cp .env .env.example
    merge_env_files
    cmp -s .env .env.example || return 1
    ! grep -q "Merged from .env.example" .env || return 1
}

# Case 6: dated comment above added block
test_dated_comment_above_added_block() {
    echo "BOT_TOKEN=mine" > .env
    printf 'BOT_TOKEN=example\nNEW_VAR=1\n' > .env.example
    merge_env_files
    local n
    n=$(grep -n "^NEW_VAR=1$" .env | cut -d: -f1) || return 1
    head -n $((n - 1)) .env | tail -n 1 | grep -Eq '^# Merged from \.env\.example on [0-9]{4}-[0-9]{2}-[0-9]{2}: .*$' || return 1
}

# Case 7: idempotency
test_idempotency() {
    echo "BOT_TOKEN=mine" > .env
    printf 'BOT_TOKEN=example\nNEW_VAR=1\n' > .env.example
    merge_env_files
    cp .env .env.after_first
    merge_env_files
    cmp -s .env .env.after_first || return 1
    [ "$(grep -c "Merged from .env.example" .env)" -eq 1 ] || return 1
}

# Case 8: value containing '=' appended verbatim
test_value_with_equals() {
    echo "BOT_TOKEN=mine" > .env
    printf 'WEBHOOK_BASE=https://x.com/a?b=c\n' > .env.example
    merge_env_files
    grep -q "^WEBHOOK_BASE=https://x.com/a?b=c$" .env || return 1
}

# Case 9: missing example -> byte-identical, exit 0
test_missing_example() {
    echo "BOT_TOKEN=mine" > .env
    cp .env .env.before
    merge_env_files
    cmp -s .env .env.before || return 1
}

# Case 10: prefix collision is not a match
test_prefix_collision_not_a_match() {
    echo "LOG_LEVEL_MAX=100" > .env
    printf 'LOG_LEVEL=WARNING\n' > .env.example
    merge_env_files
    grep -q "^LOG_LEVEL=WARNING$" .env || return 1
}

# Case 11: .env without trailing newline — appended block must not glue onto
# the user's last line (gluing would fold the header into their last value).
test_env_without_trailing_newline() {
    printf 'BOT_TOKEN=mine' > .env   # no trailing newline
    printf 'BOT_TOKEN=example\nNEW_VAR=1\n' > .env.example
    merge_env_files
    grep -q "^BOT_TOKEN=mine$" .env || return 1
    grep -q "^NEW_VAR=1$" .env || return 1
}

# Case 12: dated header joins multiple keys with ", " (plan's documented form)
test_header_joins_keys_with_comma_space() {
    echo "BOT_TOKEN=mine" > .env
    printf 'NEW_VAR=1\nNEW_OPT=2\n' > .env.example
    merge_env_files
    grep -Eq '^# Merged from \.env\.example on [0-9]{4}-[0-9]{2}-[0-9]{2}: NEW_VAR, NEW_OPT$' .env || return 1
}

# Case 13: .env.example without trailing newline — last var still merged
test_example_without_trailing_newline() {
    echo "X=9" > .env
    printf 'A=1' > .env.example   # no trailing newline
    merge_env_files
    grep -q "^A=1$" .env || return 1
}

# Case 14: missing .env -> copied from example
test_missing_env_copies_example() {
    printf 'A=1\n# B=2\n' > .env.example
    merge_env_files
    cmp -s .env .env.example || return 1
}

# Case 15: cp failure is handled gracefully — WARN + non-zero return, not a
# bare set -e abort (repo fail-fast standard: operational failure, not contract).
test_cp_failure_graceful() {
    printf 'A=1\n' > .env.example
    cp() { return 1; }   # shadow cp inside this test's scope
    local out rc=0
    out=$(merge_env_files) || rc=$?
    unset -f cp
    [ "$rc" -ne 0 ] || return 1
    [[ "$out" == *"WARN"* ]] || return 1
}

# Case 16: reverse prefix collision — example LOG_LEVEL_MAX not masked by .env LOG_LEVEL
test_reverse_prefix_collision() {
    echo "LOG_LEVEL=WARNING" > .env
    printf 'LOG_LEVEL_MAX=100\n' > .env.example
    merge_env_files
    grep -q "^LOG_LEVEL_MAX=100$" .env || return 1
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
run_test "11 env without trailing newline" test_env_without_trailing_newline
run_test "12 header joins keys with comma space" test_header_joins_keys_with_comma_space
run_test "13 example without trailing newline" test_example_without_trailing_newline
run_test "14 missing env copies example" test_missing_env_copies_example
run_test "15 cp failure graceful" test_cp_failure_graceful
run_test "16 reverse prefix collision" test_reverse_prefix_collision

echo
echo "Env merge tests: $PASS_COUNT passed, $FAIL_COUNT failed"
[ "$FAIL_COUNT" -eq 0 ]
