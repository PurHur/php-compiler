#!/usr/bin/env bash
# M2 VM driver execute smoke: spine-linked binary runs bin/vm.php run() on -r fixture (#2201).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${ROOT}/build/selfhost-lib-spine-smoke"
LINK="${ROOT}/script/bootstrap-selfhost-lib-spine-smoke-link.sh"
# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
ci_apply_llvm_memory_env

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-selfhost-vm-driver-execute-probe: LLVM 9 not found (skip)" >&2
  exit 2
fi

if [[ ! -x "${OUT}" ]]; then
  "${LINK}"
else
  ENTRY="${ROOT}/test/selfhost/compiler_lib_spine_smoke/main.php"
  if [[ "${ENTRY}" -nt "${OUT}" ]]; then
    "${LINK}"
  fi
fi

test -x "${OUT}"
set +e
out="$(
  env PHP_COMPILER_VM_DRIVER_EXECUTE=1 "${OUT}" 2>&1
)"
code=$?
set -e
if [[ "${code}" -ne 0 ]]; then
  echo "bootstrap-selfhost-vm-driver-execute-probe: native execute failed (exit ${code})" >&2
  printf '%s\n' "${out}" >&2
  exit 1
fi
if ! grep -q 'vm driver ok' <<< "${out}"; then
  echo "bootstrap-selfhost-vm-driver-execute-probe: unexpected stdout (want vm driver ok)" >&2
  printf '%s\n' "${out}" >&2
  exit 1
fi
echo "bootstrap-selfhost-vm-driver-execute-probe: OK ${OUT}"
