#!/usr/bin/env bash
#
# Cold-build check — how long does "clone the repo, compile hello world" actually take?
#
# This is the first thing a new user does, and until 2026-07-28 it cost ~9 minutes
# (#24302): warmForUserAotBuild() re-emitted the entire helper corpus on every clean
# checkout, because its only guard was a marker file under the gitignored
# build/helper-runtime-cache. #24351 skipped that when the committed per-arch cache
# matched core_fingerprint (~5s). #32122 also skips when units exist but the fingerprint
# drifted (patches/lock) — otherwise aot-smoke hits compile rc=124 inside 120s.
#
# Nothing was watching that number, so this exists to keep it from drifting back. It
# points PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR at a fresh temp directory, so it measures
# the cold path WITHOUT destroying the developer's real cache, and it bounds the run with
# a timeout — a regression shows up as a timeout rather than as a nine-minute wait.
#
# Usage:
#   script/cold-build-check.sh              # fail if slower than COLD_BUILD_MAX_SECONDS
#   COLD_BUILD_MAX_SECONDS=60 script/...    # override the budget
#   script/cold-build-check.sh --json       # machine-readable, for release-readiness
set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT" || exit 1

JSON=0
[ "${1:-}" = "--json" ] && JSON=1

: "${PHP_BIN:=php}"
: "${PHP_COMPILER_LLVM_PATH:=/opt/llvm9}"
export PHP_COMPILER_LLVM_PATH
export LD_LIBRARY_PATH="${LD_LIBRARY_PATH:-}:${PHP_COMPILER_LLVM_PATH}"
: "${PHP_COMPILER_LLVM_MEMORY_LIMIT:=8192M}"
export PHP_COMPILER_LLVM_MEMORY_LIMIT

# Budget, not a benchmark. The measured good value is ~5s; 120s is far enough above it to
# absorb a slow CI runner while still catching a return to the ~500s behaviour.
: "${COLD_BUILD_MAX_SECONDS:=120}"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# A cache dir that has never been warmed — this is what a clean checkout has.
export PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR="$WORK/cold-cache"
mkdir -p "$PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR"

printf '%s\n' '<?php echo "hi\n";' > "$WORK/hello.php"

start=$(date +%s)
timeout "$COLD_BUILD_MAX_SECONDS" "$PHP_BIN" bin/compile.php \
    -o "$WORK/hello.bin" "$WORK/hello.php" > "$WORK/compile.log" 2>&1
compile_rc=$?
end=$(date +%s)
elapsed=$((end - start))

status="ok"
message=""

if [ "$compile_rc" -eq 124 ]; then
    status="timeout"
    message="compile exceeded ${COLD_BUILD_MAX_SECONDS}s on a cold cache — the corpus warmup is running again (#24302 / #24351)"
elif [ "$compile_rc" -ne 0 ]; then
    status="compile_failed"
    message="bin/compile.php exited ${compile_rc}: $(tail -1 "$WORK/compile.log" 2>/dev/null)"
elif [ ! -x "$WORK/hello.bin" ]; then
    status="no_binary"
    message="compile reported success but emitted no binary"
else
    actual="$("$WORK/hello.bin" 2>&1)"
    run_rc=$?
    if [ "$run_rc" -ne 0 ] || [ "$actual" != "hi" ]; then
        status="wrong_output"
        message="binary exited ${run_rc} with [${actual}], expected [hi]"
    fi
fi

if [ "$JSON" -eq 1 ]; then
    printf '{"status":"%s","seconds":%d,"budget_seconds":%d,"message":"%s"}\n' \
        "$status" "$elapsed" "$COLD_BUILD_MAX_SECONDS" "${message//\"/\\\"}"
else
    if [ "$status" = "ok" ]; then
        printf 'cold-build-check: ok — clean-checkout compile of hello world took %ds (budget %ds)\n' \
            "$elapsed" "$COLD_BUILD_MAX_SECONDS"
    else
        printf 'cold-build-check: FAIL (%s) after %ds — %s\n' "$status" "$elapsed" "$message" >&2
        echo "This is the first thing a new user does. See #24302 for why it was ~9 minutes and" >&2
        echo "#24351 for the fix (skip the corpus warmup when the committed prelink cache is current)." >&2
    fi
fi

[ "$status" = "ok" ] || exit 1
exit 0
