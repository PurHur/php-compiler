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
#   script/differential-sweep.sh --aot --repeat 10    # run each built binary 10x (see below)
#
# Exit status is the number of mismatching programs, so it can gate a build.
#
# Every case must be deterministic: no clocks, no randomness, no network, no filesystem writes
# outside the process. A case that varies between runs makes the whole sweep unusable as a gate.
#
# THE PROGRAM must be deterministic. THE COMPILER'S OUTPUT NEED NOT BE. Heap corruption in a
# generated binary is a real and recurring defect class here (#23842, #23798, #23871), and it does
# not fail every run — measured rates have included 7/10, 3/10 and 2/5 for the SAME binary on the
# same input. A single run therefore passes such a case most of the time, and that has repeatedly
# caused defects to be closed while still live.
#
# `--repeat N` re-runs each already-built binary N times and reports a mismatch if ANY run differs.
# It only multiplies run time, not compile time, so `--aot --repeat 10` costs barely more than
# `--aot`. Use it before declaring a memory-safety or wrong-output fix good. A case can also opt
# itself in with a marker, so it is re-run even in a plain sweep:
#     // @differential-repeat: 10   heap corruption is intermittent here (#23842)

set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/test/differential/cases"
BACKEND=vm
QUIET=0
REPEAT=1

while [ $# -gt 0 ]; do
    case "$1" in
        --aot)    BACKEND=aot; shift ;;
        --vm)     BACKEND=vm; shift ;;
        --dir)    DIR="$2"; shift 2 ;;
        --quiet)  QUIET=1; shift ;;
        --repeat) REPEAT="$2"; shift 2 ;;
        -h|--help) sed -n '2,32p' "$0"; exit 0 ;;
        *) echo "unknown option: $1" >&2; exit 2 ;;
    esac
done

case "$REPEAT" in
    ''|*[!0-9]*) echo "--repeat needs a positive integer, got: $REPEAT" >&2; exit 2 ;;
esac
[ "$REPEAT" -ge 1 ] || { echo "--repeat must be >= 1" >&2; exit 2; }

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

    # A case may ask for extra runs because its failure mode is intermittent. The per-case marker
    # wins only when it is larger, so `--repeat 20` still applies everywhere.
    runs="$REPEAT"
    case_runs="$(sed -n 's|.*@differential-repeat: *\([0-9][0-9]*\).*|\1|p' "$f" 2>/dev/null | head -1)"
    if [ -n "$case_runs" ] && [ "$case_runs" -gt "$runs" ]; then
        runs="$case_runs"
    fi

    if [ "$BACKEND" = aot ]; then
        bin="$tmp/$(basename "$f" .php)"
        if ! timeout 1800 php bin/compile.php -o "$bin" "$f" >"$tmp/compile.log" 2>&1; then
            printf 'COMPILE %-34s (see %s)\n' "$name" "$tmp/compile.log"
            fail=$((fail + 1))
            continue
        fi
    fi

    # Repeat the RUN only — the build is already done, so N runs cost N executions, not N compiles.
    got=""
    bad=""
    bad_run=0
    matched=0
    i=1
    while [ "$i" -le "$runs" ]; do
        if [ "$BACKEND" = aot ]; then
            got="$(timeout 120 "$bin" 2>&1)"
        else
            got="$(timeout 120 php bin/vm.php "$f" 2>&1)"
        fi
        if [ "$zend" = "$got" ]; then
            matched=$((matched + 1))
        elif [ "$bad_run" -eq 0 ]; then
            bad="$got"
            bad_run="$i"
        fi
        i=$((i + 1))
    done

    if [ "$bad_run" -eq 0 ]; then
        if [ "$QUIET" -eq 1 ]; then :
        elif [ "$runs" -gt 1 ]; then printf 'ok      %-34s (%d/%d runs)\n' "$name" "$matched" "$runs"
        else printf 'ok      %s\n' "$name"
        fi
    else
        fail=$((fail + 1))
        if [ "$runs" -gt 1 ]; then
            printf 'DIFF    %-34s (%d/%d runs matched — first mismatch on run %d)\n' \
                "$name" "$matched" "$runs" "$bad_run"
        else
            printf 'DIFF    %s\n' "$name"
        fi
        printf '  zend: %s\n' "$(printf '%s' "$zend" | tr '\n' '~')"
        printf '  %-4s: %s\n' "$BACKEND" "$(printf '%s' "$bad" | tr '\n' '~')"
    fi
done

if [ "$skipped" -gt 0 ]; then
    printf '\n%d/%d match Zend (%s backend, %d skipped)\n' \
        "$((total - fail))" "$total" "$BACKEND" "$skipped"
else
    printf '\n%d/%d match Zend (%s backend)\n' "$((total - fail))" "$total" "$BACKEND"
fi
exit "$fail"
