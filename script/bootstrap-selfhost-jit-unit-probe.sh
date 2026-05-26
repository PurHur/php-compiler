#!/usr/bin/env bash
# M3 JIT unit probe: native link + execute minimal lib/JIT.php bundle (issue #2332).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENTRY="${ROOT}/test/selfhost/jit_unit_probe/main.php"
OUT="${ROOT}/build/selfhost-jit-unit-probe"

# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
ci_apply_llvm_memory_env

if [[ ! -f "${ENTRY}" ]]; then
  echo "bootstrap-selfhost-jit-unit-probe: missing ${ENTRY}" >&2
  exit 1
fi

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-selfhost-jit-unit-probe: LLVM 9 not found (skip)" >&2
  exit 2
fi

mkdir -p "${ROOT}/build"
export PHP_COMPILER_SELFHOST_AOT=1
export PHP_COMPILER_JIT_PROGRESS_FILE="${ROOT}/build/.last-jit-func-jit-unit-probe"
rm -f "${OUT}" "${PHP_COMPILER_JIT_PROGRESS_FILE}"

if ! php "${ROOT}/bin/compile.php" -o "${OUT}" "${ENTRY}" 2>&1; then
  echo "bootstrap-selfhost-jit-unit-probe: compile failed (see stderr above)" >&2
  exit 1
fi
test -x "${OUT}"

out="$("${OUT}")"
if ! grep -q 'jit_unit_probe bundle OK' <<< "${out}"; then
  echo "bootstrap-selfhost-jit-unit-probe: unexpected stdout (want jit_unit_probe bundle OK)" >&2
  printf '%s\n' "${out}" >&2
  exit 1
fi

echo "bootstrap-selfhost-jit-unit-probe: OK ${OUT}"
