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
#   script/differential-sweep.sh --jit                # MCJIT via bin/jit.php (#36221)
#   script/differential-sweep.sh --dir path/to/cases  # your own programs
#   script/differential-sweep.sh --aot --repeat 10    # run each built binary 10x (see below)
#   script/differential-sweep.sh --dir test/differential/cases/programs
#
# On RunForge / hosts without image LLVM, this re-execs via docker-exec.sh (same gate as
# phpunit.sh). Host glibc ≠ Ubuntu 22.04 image glibc: AOT binaries that match Zend in the
# image can SIGSEGV on the harness host for ordinary encapsed/call shapes (#34536).
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

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 2

# Harness-safe: AOT (and VM for consistency) must compile+run inside php-compiler:22.04-dev.
# Bare /.dockerenv is set on RunForge hosts that lack /opt/llvm9 — require image LLVM (#34536).
if ! { [[ -f /.dockerenv ]] && [[ -f /opt/llvm9/libLLVM-9.so.1 ]]; } \
    && [[ "${PHP_COMPILER_IN_DOCKER:-0}" != "1" ]]; then
    wrap_args=()
    prev=""
    for a in "$@"; do
        if [[ "$prev" == "--dir" ]]; then
            # Repo-absolute host paths must become /compiler/... inside the image.
            case "$a" in
                "$ROOT"/*) a="/compiler/${a#"$ROOT"/}" ;;
                /*) ;; # leave other abs paths; they only work if bind-visible
            esac
            wrap_args+=("$a")
            prev=""
            continue
        fi
        wrap_args+=("$a")
        prev="$a"
    done
    # bash `printf '%q '` with zero operands prints `''`, which becomes `$1=""` after
    # re-exec and trips `unknown option:` (#34860). Only quote when args exist.
    if [ "${#wrap_args[@]}" -eq 0 ]; then
        exec ./script/docker-exec.sh -- bash -lc "source script/php-env.sh && ./script/differential-sweep.sh"
    fi
    args=$(printf '%q ' "${wrap_args[@]}")
    # shellcheck disable=SC2086
    exec ./script/docker-exec.sh -- bash -lc "source script/php-env.sh && ./script/differential-sweep.sh ${args}"
fi

DEFAULT_DIR="$ROOT/test/differential/cases"
DIR="$DEFAULT_DIR"
BACKEND=vm
QUIET=0
REPEAT=1

while [ $# -gt 0 ]; do
    case "$1" in
        --aot)    BACKEND=aot; shift ;;
        --jit)    BACKEND=jit; shift ;;
        --vm)     BACKEND=vm; shift ;;
        --dir)    DIR="$2"; shift 2 ;;
        --quiet)  QUIET=1; shift ;;
        --repeat) REPEAT="$2"; shift 2 ;;
        -h|--help) sed -n '2,45p' "$0"; exit 0 ;;
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
    #     // @differential-skip-jit: MCJIT whole-script VM fallback / gap (#98 / #36221)
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
        elif [ "$BACKEND" = jit ]; then
            got="$(timeout 300 php -d error_reporting=1 -d log_errors=1 -d display_errors=stderr bin/jit.php "$f" 2>&1)"
        else
            got="$(timeout 120 php -d error_reporting=1 -d log_errors=1 -d display_errors=stderr bin/vm.php "$f" 2>&1)"
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

if [ "$total" -eq 0 ]; then
    echo "differential-sweep: no .php cases under $DIR (empty corpus is not a pass — #36248)" >&2
    exit 2
fi

min_cases=0
count_file=""
if [ -f "$DIR/COUNT" ]; then
    count_file="$DIR/COUNT"
elif [ "$DIR" = "$DEFAULT_DIR" ] && [ -f "$ROOT/test/differential/COUNT" ]; then
    count_file="$ROOT/test/differential/COUNT"
fi
if [ -n "$count_file" ]; then
    min_cases="$(tr -d '[:space:]' <"$count_file")"
    case "$min_cases" in
        ''|*[!0-9]*)
            echo "differential-sweep: invalid MIN_CASES in $count_file: $(cat "$count_file")" >&2
            exit 2
            ;;
    esac
    if [ "$total" -lt "$min_cases" ]; then
        echo "differential-sweep: found $total case(s) under $DIR but $count_file requires >= $min_cases (#36248/#36221)" >&2
        exit 2
    fi
fi

if [ "$skipped" -gt 0 ]; then
    printf '\n%d/%d match Zend (%s backend, %d skipped)\n' \
        "$((total - fail))" "$total" "$BACKEND" "$skipped"
else
    printf '\n%d/%d match Zend (%s backend)\n' "$((total - fail))" "$total" "$BACKEND"
fi
exit "$fail"
