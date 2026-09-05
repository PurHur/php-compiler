#!/usr/bin/env bash
#
# Weekly #10 soak path for #36397: AOT differential on the COW churn case
# under PHP_COMPILER_ASAN=1 with --repeat 10 (same case as the non-ASan
# @differential-repeat marker).
#
# Default: single-case dir test/runtime-assert/soak (COUNT=1).
# RUNTIME_ASSERT_SOAK_FULL=1 expands to the full differential corpus (slow).
#
# Usage:
#   ./script/runtime-assert/differential-soak.sh
#   make runtime-assert-differential-soak
#
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

if [[ ! -f "$ROOT/bin/compile.php" ]]; then
  echo "runtime-assert-differential-soak: FAIL — repo root misresolved ($ROOT missing bin/compile.php)" >&2
  exit 1
fi

if ! { [[ -f /.dockerenv ]] && [[ -f /opt/llvm9/libLLVM-9.so.1 ]]; } \
    && [[ "${PHP_COMPILER_IN_DOCKER:-0}" != "1" ]]; then
    exec ./script/docker-exec.sh -- bash -lc "source script/php-env.sh && ./script/runtime-assert/differential-soak.sh"
fi

: "${PHP_BIN:=php}"
: "${PHP_COMPILER_LLVM_PATH:=/opt/llvm9}"
export PHP_COMPILER_LLVM_PATH
export LD_LIBRARY_PATH="${LD_LIBRARY_PATH:-}:${PHP_COMPILER_LLVM_PATH}"
export PHP_COMPILER_ASAN=1
export PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR="${PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR:-$(mktemp -d /tmp/phpc-soak-cache.XXXXXX)}"
export ASAN_OPTIONS="${ASAN_OPTIONS:-detect_leaks=0:halt_on_error=1:abort_on_error=1:quarantine_size_mb=16}"
export UBSAN_OPTIONS="${UBSAN_OPTIONS:-halt_on_error=1}"

SOAK_DIR="$ROOT/test/runtime-assert/soak"
if [[ ! -d "$SOAK_DIR" ]] || [[ ! -f "$SOAK_DIR/refcount_cow_churn_36397.php" ]]; then
  echo "runtime-assert-differential-soak: FAIL — missing soak corpus under $SOAK_DIR" >&2
  exit 1
fi

echo "runtime-assert-differential-soak: ASan AOT differential soak…"

if [[ "${RUNTIME_ASSERT_SOAK_FULL:-0}" == "1" ]]; then
  ./script/differential-sweep.sh --aot --repeat 10
else
  ./script/differential-sweep.sh --aot --repeat 10 --dir "$SOAK_DIR"
fi

echo "runtime-assert-differential-soak: OK"
