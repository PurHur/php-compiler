#!/usr/bin/env bash
# M5 slice: compile production driver (bin/compile.php) via a native compile driver.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"

# shellcheck source=php-env.sh
source "${ROOT}/script/php-env.sh"
ci_apply_llvm_memory_env

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-native-compile-driver-smoke: LLVM 9 not found (skip)" >&2
  exit 2
fi

# Delegate to M5 driver smoke (functional host compile — not emit sidecar stub #1521).
export PHP_COMPILER_M5_DRIVER_OUT="${ROOT}/build/bin-compile-aot"
exec "${ROOT}/script/bootstrap-selfhost-driver-smoke.sh"
