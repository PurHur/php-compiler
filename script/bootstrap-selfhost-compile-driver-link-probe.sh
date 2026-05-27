#!/usr/bin/env bash
# M3 compile_driver.php native link probe (#1768): real-lowering bundle must link without LLVM verify failure.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENTRY="${ROOT}/test/selfhost/compiler_helloworld_smoke/compile_driver.php"
OUT="${ROOT}/build/selfhost-helloworld-compile-driver"
# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
ci_apply_llvm_memory_env

if [[ ! -f "${ENTRY}" ]]; then
  echo "bootstrap-selfhost-compile-driver-link-probe: missing ${ENTRY}" >&2
  exit 1
fi

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-selfhost-compile-driver-link-probe: LLVM 9 not found (skip)" >&2
  exit 2
fi

mkdir -p "${ROOT}/build"
export PHP_COMPILER_SELFHOST_AOT=1
export PHP_COMPILER_M3_COMPILE_DRIVER=1
export PHP_COMPILER_M3_COMPILE_DRIVER_MAIN=1
export PHP_COMPILER_JIT_PROGRESS_FILE="${ROOT}/build/.last-jit-func-compile-driver-link"
rm -f "${OUT}" "${PHP_COMPILER_JIT_PROGRESS_FILE}"

set +e
php "${ROOT}/bin/compile.php" -o "${OUT}" "${ENTRY}" >/dev/null 2>&1
code=$?
set -e
if [[ "${code}" -eq 139 ]]; then
  echo "bootstrap-selfhost-compile-driver-link-probe: segfault during link (#1768)" >&2
  exit 1
fi
if [[ ! -x "${OUT}" ]]; then
  echo "bootstrap-selfhost-compile-driver-link-probe: link failed (exit ${code})" >&2
  exit 1
fi

echo "bootstrap-selfhost-compile-driver-link-probe: OK ${OUT}"
