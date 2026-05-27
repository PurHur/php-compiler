#!/usr/bin/env bash
# M3 VM unit probe: lib/VM.php bundle AOT native link + optional VM run (issue #2354, #2619).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENTRY="${ROOT}/test/selfhost/vm_unit_probe/main.php"
OUT="${ROOT}/build/selfhost-vm-unit-probe"
# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
ci_apply_llvm_memory_env

if [[ ! -f "${ENTRY}" ]]; then
  echo "bootstrap-selfhost-vm-unit-probe: missing ${ENTRY}" >&2
  exit 1
fi

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-selfhost-vm-unit-probe: LLVM 9 not found (skip)" >&2
  exit 2
fi

mkdir -p "${ROOT}/build"
export PHP_COMPILER_SELFHOST_AOT=1
export PHP_COMPILER_JIT_PROGRESS_FILE="${ROOT}/build/.last-jit-func-vm-unit-probe"
rm -f "${OUT}" "${PHP_COMPILER_JIT_PROGRESS_FILE}"
if ! php "${ROOT}/bin/compile.php" -o "${OUT}" "${ENTRY}" 2>&1; then
  echo "bootstrap-selfhost-vm-unit-probe: compile failed (progress gate; see stderr above)" >&2
  exit 1
fi
test -x "${OUT}"

bundle_out="$("${OUT}")"
if ! grep -q 'vm_unit_probe bundle OK' <<< "${bundle_out}"; then
  echo "bootstrap-selfhost-vm-unit-probe: unexpected link stdout (want vm_unit_probe bundle OK)" >&2
  printf '%s\n' "${bundle_out}" >&2
  exit 1
fi

if [[ "${BOOTSTRAP_VM_UNIT_PROBE_EXECUTE:-0}" == "1" ]]; then
  execute_out="$(
    env PHP_COMPILER_VM_UNIT_PROBE_EXECUTE=1 "${OUT}" 2>&1
  )"
  if ! grep -q 'vm_unit_probe_run OK' <<< "${execute_out}"; then
    echo "bootstrap-selfhost-vm-unit-probe: execute phase failed (want vm_unit_probe_run OK)" >&2
    printf '%s\n' "${execute_out}" >&2
    exit 1
  fi
  echo "bootstrap-selfhost-vm-unit-probe: execute OK (vm_unit_probe_run)"
fi

echo "bootstrap-selfhost-vm-unit-probe: OK ${OUT}"
