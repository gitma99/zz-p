#!/bin/bash
# Verify the LOG_LEVEL access-log gate in docker-entrypoint.sh:
# the CustomLog line is rewritten to /dev/null unless LOG_LEVEL is INFO/DEBUG.
# No apache2 required: extracts the gate block from the entrypoint and runs it
# against a fixture site conf.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

GATE="$(mktemp)"
trap 'rm -f "$GATE"' EXIT
awk '/^# Gate Apache access log/{f=1} f{print} f && /^esac$/{exit}' \
    "$REPO_ROOT/docker-entrypoint.sh" > "$GATE"
[ -s "$GATE" ] || { echo "FAIL: gate block not found in docker-entrypoint.sh"; exit 1; }

check() { # $1=level  $2=expect(on|off)
    local conf; conf="$(mktemp)"
    printf '    ErrorLog /proc/self/fd/2\n    CustomLog /proc/self/fd/1 combined\n' > "$conf"
    LOG_LEVEL="$1" APACHE_SITE_CONF="$conf" bash -c "source '$GATE'"
    local line; line="$(grep '^    CustomLog' "$conf")"
    case "$2" in
        on)  [ "$line" = "    CustomLog /proc/self/fd/1 combined" ] || { echo "FAIL: level='$1' expected ON, got: $line";  rm -f "$conf"; exit 1; } ;;
        off) [ "$line" = "    CustomLog /dev/null combined" ]        || { echo "FAIL: level='$1' expected OFF, got: $line"; rm -f "$conf"; exit 1; } ;;
    esac
    rm -f "$conf"
    echo "ok: LOG_LEVEL='$1' -> $2"
}

check INFO on
check DEBUG on
check info on
check debug on
check WARNING off
check ERROR off
check CRITICAL off
check warning off
check "" off
echo "PASS: access_log_gate_test"
