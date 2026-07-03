#!/usr/bin/env bash
# Native bootstrap test harness starter (#15599).
# Compiles and runs one curated bootstrap-aot smoke via gen-0/gen-2 driver — no Zend PHPUnit.
#
# Usage:
#   ./script/bootstrap-native-test.sh
#   make bootstrap-native-test
#
# Exit codes:
#   0 — smoke passed
#   1 — failure
#   2 — LLVM unavailable (skip)
#
# See docs/bootstrap-dev-workflow.md — Tier 1.5 (native test harness starter)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
FIXTURE="${ROOT}/test/bootstrap-aot/compiler_smoke_standalone.php"
OUT="${ROOT}/build/native-test-harness-smoke"
EXPECTED_STDOUT="compiler smoke"

# shellcheck source=php-env.sh
source "${ROOT}/script/php-env.sh"
ci_apply_llvm_memory_env

if [[ ! -f "${FIXTURE}" ]]; then
  echo "bootstrap-native-test: missing fixture ${FIXTURE}" >&2
  exit 1
fi

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-native-test: LLVM 9 not found (skip)" >&2
  exit 2
fi

# shellcheck source=bootstrap-ensure-inventory-argv-driver.sh
source "${ROOT}/script/bootstrap-ensure-inventory-argv-driver.sh"
# shellcheck source=bootstrap-resolve-compile-invoke.sh
source "${ROOT}/script/bootstrap-resolve-compile-invoke.sh"

mkdir -p "${ROOT}/build"
rm -f "${OUT}"

BOOTSTRAP_NO_ZEND_FALLBACK=1
if ! bootstrap_ensure_inventory_argv_driver_ssot "${ROOT}/build/bin-compile-aot-inventory"; then
  if [[ ! -x "${ROOT}/build/bin-compile-aot-inventory" ]] \
    && [[ ! -x "${ROOT}/build/bin-compile-aot" ]] \
    && [[ ! -x "${ROOT}/prelinked/bootstrap-gen0/bin-compile-aot" ]]; then
    echo "bootstrap-native-test: no gen-0/gen-2 native driver (run make bootstrap-selfhost-ensure-gen0-driver)" >&2
    exit 1
  fi
  echo "bootstrap-native-test: driver ensure skipped — bootstrap_compile_invoke will try native candidates (#15599)" >&2
fi
if ! bootstrap_compile_invoke "${OUT}" "${FIXTURE}" env -u PHP_COMPILER_M3_SOURCE -u PHP_COMPILER_M3_OUT; then
  echo "bootstrap-native-test: native compile failed" >&2
  exit 1
fi

if [[ ! -x "${OUT}" ]]; then
  echo "bootstrap-native-test: missing executable ${OUT}" >&2
  exit 1
fi

run_out="$("${OUT}" 2>&1)"
if ! grep -q "${EXPECTED_STDOUT}" <<< "${run_out}"; then
  echo "bootstrap-native-test: unexpected stdout (want: ${EXPECTED_STDOUT})" >&2
  printf '%s\n' "${run_out}" >&2
  exit 1
fi

echo "bootstrap-native-test: OK (${BOOTSTRAP_COMPILE_DRIVER}) ${FIXTURE}"
