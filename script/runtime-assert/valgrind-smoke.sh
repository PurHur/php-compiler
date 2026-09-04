#!/usr/bin/env bash
#
# Optional valgrind pass over a tiny AOT binary (#36397).
# Skip (exit 0) when valgrind is not installed — the CI image often lacks it.
# When present, fail on any error (valgrind --error-exitcode=1).
#
# Usage:
#   ./script/runtime-assert/valgrind-smoke.sh
#   make runtime-assert-valgrind-smoke
#
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if ! { [[ -f /.dockerenv ]] && [[ -f /opt/llvm9/libLLVM-9.so.1 ]]; } \
    && [[ "${PHP_COMPILER_IN_DOCKER:-0}" != "1" ]]; then
    exec ./script/docker-exec.sh -- bash -lc "source script/php-env.sh && ./script/runtime-assert/valgrind-smoke.sh"
fi

if ! command -v valgrind >/dev/null 2>&1; then
  echo "runtime-assert-valgrind-smoke: valgrind not installed — skip (exit 0)"
  exit 0
fi

: "${PHP_BIN:=php}"
: "${PHP_COMPILER_LLVM_PATH:=/opt/llvm9}"
export PHP_COMPILER_LLVM_PATH
export LD_LIBRARY_PATH="${LD_LIBRARY_PATH:-}:${PHP_COMPILER_LLVM_PATH}"
export PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR="${PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR:-$(mktemp -d /tmp/phpc-vg-cache.XXXXXX)}"

WORK="$(mktemp -d /tmp/phpc-valgrind-smoke.XXXXXX)"
cleanup() { rm -rf "$WORK"; }
trap cleanup EXIT

SRC="$WORK/hi.php"
BIN="$WORK/hi.bin"
printf '%s\n' '<?php echo "hi\n";' > "$SRC"

echo "runtime-assert-valgrind-smoke: compile hello…"
"$PHP_BIN" bin/compile.php -o "$BIN" "$SRC"
[[ -x "$BIN" ]]

echo "runtime-assert-valgrind-smoke: valgrind --error-exitcode=1…"
# Suppress leak noise from request-lifetime objects; still catch invalid reads/writes.
out="$(valgrind --quiet --error-exitcode=1 --leak-check=no --track-origins=yes "$BIN" 2>&1)" || {
  echo "runtime-assert-valgrind-smoke: FAIL" >&2
  echo "$out" >&2
  exit 1
}
# Command substitution strips trailing newline.
if [[ "$out" != "hi" ]]; then
  echo "runtime-assert-valgrind-smoke: FAIL — unexpected stdout: $(printf '%q' "$out")" >&2
  exit 1
fi

echo "runtime-assert-valgrind-smoke: OK"
