#!/usr/bin/env bash
#
# Performance regression gate for benchmarks/ (#36196) and benchmarks/v2/ (#36385).
#
# Compares AOT/Zend wall-time ratios and LLVM IR size against the committed baseline.
# Ratios are measured in the same job (best-of-3) so shared-box noise cancels; IR line
# counts are load-independent.
#
# Usage:
#   script/bench-gate.sh            # check against committed baseline
#   script/bench-gate.sh --update   # bless benchmarks/BASELINE.json from current master
#   script/bench-gate.sh --v2       # v2 suite gate (#36385)
#   script/bench-gate.sh --v2 --update
#   script/bench-gate.sh --compile  # compile-time gate (#36387)
#   script/bench-gate.sh --compile --update
#
# On RunForge / hosts without image LLVM, re-execs via docker-exec.sh (same as aot-smoke.sh).
set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT" || exit 1

if ! { [[ -f /.dockerenv ]] && [[ -f /opt/llvm9/libLLVM-9.so.1 ]]; } \
    && [[ "${PHP_COMPILER_IN_DOCKER:-0}" != "1" ]]; then
    if [ "$#" -eq 0 ]; then
        exec ./script/docker-exec.sh -- bash -lc "source script/php-env.sh && ./script/bench-gate.sh"
    fi
    args=$(printf '%q ' "$@")
    # shellcheck disable=SC2086
    exec ./script/docker-exec.sh -- bash -lc "source script/php-env.sh && ./script/bench-gate.sh ${args}"
fi

: "${PHP_BIN:=php}"
: "${PHP_COMPILER_LLVM_PATH:=/opt/llvm9}"
export PHP_COMPILER_LLVM_PATH
export LD_LIBRARY_PATH="${LD_LIBRARY_PATH:-}:${PHP_COMPILER_LLVM_PATH}"
: "${PHP_COMPILER_LLVM_MEMORY_LIMIT:=8192M}"
export PHP_COMPILER_LLVM_MEMORY_LIMIT
: "${PHP_COMPILER_BENCH_TIMEOUT:=300}"
: "${PHP_COMPILER_BENCH_BUILD_TIMEOUT:=900}"

ZEND_BIN="$(command -v php)"
if [ -z "$ZEND_BIN" ]; then
    echo "bench-gate: php not found in PATH" >&2
    exit 1
fi
export PHP_8_2="$ZEND_BIN"

exec "$PHP_BIN" "$REPO_ROOT/script/bench-gate.php" "$@"
