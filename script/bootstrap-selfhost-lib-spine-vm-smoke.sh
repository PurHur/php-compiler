#!/usr/bin/env bash
# M2 lib spine VM -r smoke: reuse build/selfhost-lib-spine-smoke, run bin/vm.php -r echo path (#1846).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${ROOT}/build/selfhost-lib-spine-smoke"
LINK="${ROOT}/script/bootstrap-selfhost-lib-spine-smoke-link.sh"
# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
ci_apply_llvm_memory_env

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-selfhost-lib-spine-vm-smoke: LLVM 9 not found (skip)" >&2
  exit 2
fi

if [[ ! -x "${OUT}" ]]; then
  "${LINK}"
else
  # Re-link when spine entry is newer than the native binary.
  ENTRY="${ROOT}/test/selfhost/compiler_lib_spine_smoke/main.php"
  if [[ "${ENTRY}" -nt "${OUT}" ]]; then
    "${LINK}"
  fi
fi

test -x "${OUT}"
out="$({ PHP_COMPILER_VM_SPINE_SMOKE=1 "${OUT}"; })"
if ! grep -q 'vm-spine-ok' <<< "${out}"; then
  echo "bootstrap-selfhost-lib-spine-vm-smoke: unexpected stdout (want vm-spine-ok)" >&2
  printf '%s\n' "${out}" >&2
  exit 1
fi
echo "bootstrap-selfhost-lib-spine-vm-smoke: OK ${OUT}"
