#!/usr/bin/env bash
# Run the full test suite inside the CI-mirroring test container.
# Mirrors .github/workflows/ci.yml "tests" job:
#   - unit tests:   tests/unit/run.sh
#   - integration:  tests/integration/*_test.*
#
# Expects MYSQL_HOST/MYSQL_PORT/MYSQL_USER/MYSQL_PASS/MYSQL_DB to be set.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO_ROOT"

echo "==> unit tests"
bash tests/unit/run.sh

echo "==> integration tests"
for f in tests/integration/*_test.*; do
    [ -f "$f" ] || continue
    echo "==> $f"
    case "$f" in
        *.php) php "$f" || exit 1 ;;
        *.sh)  bash "$f" || exit 1 ;;
    esac
done

echo "==> ALL TESTS PASSED"
