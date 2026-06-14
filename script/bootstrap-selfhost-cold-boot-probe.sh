#!/usr/bin/env bash
# M5 cold boot: empty build/ links compiler_minimal via prelinked gen-0 only (#3053).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
ci_apply_llvm_memory_env

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-selfhost-cold-boot-probe: LLVM 9 not found (skip)" >&2
  exit 2
fi

rm -rf "${ROOT}/build"
exec env BOOTSTRAP_M5_NO_ZEND=1 "${ROOT}/script/bootstrap-selfhost-link.sh"
