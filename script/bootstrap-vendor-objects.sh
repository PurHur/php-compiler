#!/usr/bin/env bash
# M5 vendor prelink: generate bundles and optionally AOT-compile to prelinked/bootstrap-vendor/*.o (#1416).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
ci_apply_llvm_memory_env

compile=0
check=0
for arg in "$@"; do
  case "$arg" in
    --compile) compile=1 ;;
    --check) check=1 ;;
    *)
      echo "bootstrap-vendor-objects.sh: unknown argument: ${arg}" >&2
      exit 1
      ;;
  esac
done

args=()
if [[ "${check}" -eq 1 ]]; then
  args+=(--check)
fi
if [[ "${compile}" -eq 1 ]]; then
  if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
    echo "bootstrap-vendor-objects: LLVM 9 not found (skip compile)" >&2
    exit 2
  fi
  args+=(--compile)
fi

"$PHP_BIN" "${PHP_OPTS[@]}" "${ROOT}/script/bootstrap-vendor-objects.php" "${args[@]}"
