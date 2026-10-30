#!/usr/bin/env bash
# Runs all zero-dependency unit tests under tests/unit.
# Each *_test.php is self-contained and exits non-zero on failure.
set -u

dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
pass=0
fail=0

for test_file in "$dir"/*_test.php; do
    [ -e "$test_file" ] || continue
    name="$(basename "$test_file")"
    out="$(php "$test_file" 2>&1)"
    rc=$?
    if [ $rc -eq 0 ]; then
        pass=$((pass + 1))
        echo "PASS $name"
    else
        fail=$((fail + 1))
        echo "FAIL $name (exit $rc)"
        echo "$out" | sed 's/^/    /'
    fi
done

echo
echo "Unit tests: $pass passed, $fail failed"
if [ "$fail" -ne 0 ]; then
    exit 1
fi
exit 0
