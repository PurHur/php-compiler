#!/usr/bin/env bash
#
# Differential sweep: run each program under Zend and under the compiler, compare stdout+stderr.
#
# The compliance suite asserts against recorded expectations, so it only catches what someone
# already thought to record. This asserts against Zend itself, which is what makes it useful for
# finding silent wrong output — code that runs to completion and prints the wrong thing.
#
#   script/differential-sweep.sh                      # VM backend, bundled corpus
#   script/differential-sweep.sh --aot                # AOT backend (slow; compiles each program)
#   script/differential-sweep.sh --dir path/to/cases  # your own programs
#
# Exit status is the number of mismatching programs, so it can gate a build.
#
# Every case must be deterministic: no clocks, no randomness, no network, no filesystem writes
# outside the process. A case that varies between runs makes the whole sweep unusable as a gate.

set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/test/differential/cases"
BACKEND=vm
QUIET=0

while [ $# -gt 0 ]; do
    case "$1" in
        --aot)   BACKEND=aot; shift ;;
        --vm)    BACKEND=vm; shift ;;
        --dir)   DIR="$2"; shift 2 ;;
        --quiet) QUIET=1; shift ;;
        -h|--help) sed -n '2,20p' "$0"; exit 0 ;;
        *) echo "unknown option: $1" >&2; exit 2 ;;
    esac
done

if [ ! -d "$DIR" ]; then
    echo "no such directory: $DIR" >&2
    exit 2
fi

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 2

fail=0
total=0
skipped=0
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

for f in "$DIR"/*.php; do
    [ -e "$f" ] || continue
    name="$(basename "$f")"

    # A case may declare a backend it cannot pass, e.g. it exercises a feature that mode does not
    # implement. Without this, such a case fails forever regardless of compiler state and the exit
    # status stops meaning "regressions" — see #23779. The marker must carry a reason:
    #     // @differential-skip-aot: var_dump() of non-scalars needs Runtime->vm (#23540)
    # Use it ONLY for genuinely unsupported features, never to silence a real defect.
    if grep -q "@differential-skip-$BACKEND\b" "$f" 2>/dev/null; then
        reason="$(sed -n "s|.*@differential-skip-$BACKEND: *||p" "$f" | head -1)"
        [ "$QUIET" -eq 1 ] || printf 'skip    %-34s %s\n' "$name" "$reason"
        skipped=$((skipped + 1))
        continue
    fi

    total=$((total + 1))

    zend="$(timeout 120 php "$f" 2>&1)"

    if [ "$BACKEND" = aot ]; then
        bin="$tmp/$(basename "$f" .php)"
        if ! timeout 1800 php bin/compile.php -o "$bin" "$f" >"$tmp/compile.log" 2>&1; then
            printf 'COMPILE %-34s (see %s)\n' "$name" "$tmp/compile.log"
            fail=$((fail + 1))
            continue
        fi
        got="$(timeout 120 "$bin" 2>&1)"
    else
        got="$(timeout 120 php bin/vm.php "$f" 2>&1)"
    fi

    if [ "$zend" = "$got" ]; then
        [ "$QUIET" -eq 1 ] || printf 'ok      %s\n' "$name"
    else
        fail=$((fail + 1))
        printf 'DIFF    %s\n' "$name"
        printf '  zend: %s\n' "$(printf '%s' "$zend" | tr '\n' '~')"
        printf '  %-4s: %s\n' "$BACKEND" "$(printf '%s' "$got" | tr '\n' '~')"
    fi
done

if [ "$skipped" -gt 0 ]; then
    printf '\n%d/%d match Zend (%s backend, %d skipped)\n' \
        "$((total - fail))" "$total" "$BACKEND" "$skipped"
else
    printf '\n%d/%d match Zend (%s backend)\n' "$((total - fail))" "$total" "$BACKEND"
fi
exit "$fail"
