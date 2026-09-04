#!/usr/bin/env bash
#
# ASan/UBSan link smoke (#36397): compile + run a trivial AOT binary with
# PHP_COMPILER_ASAN=1. Catches the raw-ld "-f may not be used without -shared"
# regression (sanitizer flags must go through clang/gcc).
#
# Not the 7-day streak Done-when — that needs a scheduled host job. This is the
# implementer gate that proves the link path works.
#
# Usage:
#   ./script/runtime-assert/asan-smoke.sh
#   make runtime-assert-asan-smoke
#
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if ! { [[ -f /.dockerenv ]] && [[ -f /opt/llvm9/libLLVM-9.so.1 ]]; } \
    && [[ "${PHP_COMPILER_IN_DOCKER:-0}" != "1" ]]; then
    exec ./script/docker-exec.sh -- bash -lc "source script/php-env.sh && ./script/runtime-assert/asan-smoke.sh"
fi

: "${PHP_BIN:=php}"
: "${PHP_COMPILER_LLVM_PATH:=/opt/llvm9}"
export PHP_COMPILER_LLVM_PATH
export LD_LIBRARY_PATH="${LD_LIBRARY_PATH:-}:${PHP_COMPILER_LLVM_PATH}"
export PHP_COMPILER_ASAN=1
# Unique cache so concurrent helper-runtime jobs do not collide.
export PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR="${PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR:-$(mktemp -d /tmp/phpc-asan-cache.XXXXXX)}"
# Leak reports from helper/runtime allocations are out of scope for this smoke.
export ASAN_OPTIONS="${ASAN_OPTIONS:-detect_leaks=0:halt_on_error=1}"
export UBSAN_OPTIONS="${UBSAN_OPTIONS:-halt_on_error=1}"

WORK="$(mktemp -d /tmp/phpc-asan-smoke.XXXXXX)"
cleanup() { rm -rf "$WORK"; }
trap cleanup EXIT

SRC="$WORK/hi.php"
BIN="$WORK/hi.bin"
printf '%s\n' '<?php echo "hi\n";' > "$SRC"

echo "runtime-assert-asan-smoke: compile with PHP_COMPILER_ASAN=1…"
if ! "$PHP_BIN" bin/compile.php -o "$BIN" "$SRC"; then
  echo "runtime-assert-asan-smoke: FAIL — compile/link with PHP_COMPILER_ASAN=1" >&2
  exit 1
fi
if [[ ! -x "$BIN" ]]; then
  echo "runtime-assert-asan-smoke: FAIL — missing binary $BIN" >&2
  exit 1
fi

echo "runtime-assert-asan-smoke: run…"
out="$("$BIN" 2>&1)" || {
  echo "runtime-assert-asan-smoke: FAIL — binary exited non-zero" >&2
  echo "$out" >&2
  exit 1
}
# Command substitution strips one trailing newline; expect bare "hi".
if [[ "$out" != "hi" ]]; then
  echo "runtime-assert-asan-smoke: FAIL — unexpected stdout: $(printf '%q' "$out")" >&2
  exit 1
fi

echo "runtime-assert-asan-smoke: OK (ASan-linked hello)"
