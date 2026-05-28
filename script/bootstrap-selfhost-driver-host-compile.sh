#!/usr/bin/env bash
# M5: Zend host-compile bin/compile.php into a native driver (#1521).
#
# BOOTSTRAP_M5_DRIVER_HOST_FULL_CLI=1 enables full cli_driver + native $argv bridge (#2794).
# Default (without FULL_CLI) is link-only sidecar bytes for faster M5 iteration.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${PHP_COMPILER_M5_DRIVER_OUT:-${ROOT}/build/selfhost-compile-driver}"

# shellcheck source=php-env.sh
source "${ROOT}/script/php-env.sh"
ci_apply_llvm_memory_env

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-selfhost-driver-host-compile: LLVM 9 not found (skip)" >&2
  exit 2
fi

mkdir -p "${ROOT}/build"
rm -f "${OUT}" "${ROOT}/build/.last-jit-func-m5-driver-host"

export PHP_COMPILER_SELFHOST_AOT=1
export PHP_COMPILER_M3_COMPILE_DRIVER=1
export PHP_COMPILER_JIT_PROGRESS_FILE="${ROOT}/build/.last-jit-func-m5-driver-host"
unset PHP_COMPILER_EMIT_HELPER_LINK PHP_COMPILER_M3_EMIT_TU

if [[ "${BOOTSTRAP_M5_DRIVER_HOST_FULL_CLI:-0}" == "1" ]]; then
  export PHP_COMPILER_M5_DRIVER_HOST=1
  export PHP_COMPILER_M4_BIN_COMPILE_DRIVER=1
  export PHP_COMPILER_M3_EMIT_LOG_PREFIX=helloworld_compile_smoke
  export PHP_COMPILER_CLI_COMPILED=1
  export PHP_COMPILER_CLI_SKIP_VENDOR=1
  echo "bootstrap-selfhost-driver-host-compile: attempting full cli bundle (BOOTSTRAP_M5_DRIVER_HOST_FULL_CLI=1)" >&2
fi

set +e
link_out="$(
  php "${ROOT}/bin/compile.php" -o "${OUT}" "${ROOT}/bin/compile.php" 2>&1
)"
link_code=$?
set -e
printf '%s\n' "${link_out}"

if [[ "${link_code}" -eq 139 ]]; then
  echo "bootstrap-selfhost-driver-host-compile: LLVM 9 segfault linking cli bundle (exit 139 — #1521)" >&2
  echo "bootstrap-selfhost-driver-host-compile: retry without BOOTSTRAP_M5_DRIVER_HOST_FULL_CLI=1 for sidecar stub" >&2
  exit 139
fi

if [[ "${link_code}" -ne 0 || ! -x "${OUT}" ]]; then
  echo "bootstrap-selfhost-driver-host-compile: link failed (exit ${link_code})" >&2
  exit 1
fi

echo "bootstrap-selfhost-driver-host-compile: OK ${OUT}"
