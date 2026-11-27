#!/usr/bin/env bash
# Unit test: sql/sql.php is CLI-only and fails closed.
# install.sh and docker-entrypoint.sh parse its JSON, so a silent success or a
# PHP fatal here would let a broken schema look installed.
set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SQL="$REPO_ROOT/sql/sql.php"
PHP_BIN="${PHP_BIN:-php}"

fail() { echo "FAIL: $1"; exit 1; }

[ -f "$SQL" ] || fail "sql/sql.php not found at $SQL"

# 1. No credentials in the environment -> exit 1, JSON status false, error_code 401.
out=$(env -u DB_HOST -u DB_USER -u DB_PASS -u DB_NAME "$PHP_BIN" "$SQL" 2>/dev/null)
rc=$?
[ "$rc" -eq 1 ] || fail "missing credentials should exit 1, got $rc (output: $out)"
flat=$(printf '%s' "$out" | tr -d '[:space:]')
printf '%s' "$flat" | grep -q '"status":false' || fail "missing credentials should report status false: $out"
printf '%s' "$flat" | grep -q '"error_code":401' || fail "missing credentials should report error_code 401: $out"

# 2. Unreachable server -> exit 1, JSON status false (no fatal, no silent 0).
out=$(DB_HOST=localhost DB_USER=nobody DB_PASS=x DB_NAME=nodb "$PHP_BIN" "$SQL" 2>/dev/null)
rc=$?
[ "$rc" -eq 1 ] || fail "unreachable database should exit 1, got $rc (output: $out)"
flat=$(printf '%s' "$out" | tr -d '[:space:]')
printf '%s' "$flat" | grep -q '"status":false' || fail "unreachable database should report status false: $out"

echo "OK sql_cli_test.sh"
