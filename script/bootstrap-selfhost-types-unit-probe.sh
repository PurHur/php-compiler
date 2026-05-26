#!/usr/bin/env bash
# M3 PHPTypes unit probe: JIT Type external-class bundle native link + run (issue #2430).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENTRY="${ROOT}/test/selfhost/types_unit_probe/main.php"
OUT="${ROOT}/build/selfhost-types-unit-probe"

# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
ci_apply_llvm_memory_env

if [[ ! -f "${ENTRY}" ]]; then
  echo "bootstrap-selfhost-types-unit-probe: missing ${ENTRY}" >&2
  exit 1
fi

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-selfhost-types-unit-probe: LLVM 9 not found (skip)" >&2
  exit 2
fi

mkdir -p "${ROOT}/build"
export PHP_COMPILER_SELFHOST_AOT=1
export PHP_COMPILER_JIT_PROGRESS_FILE="${ROOT}/build/.last-jit-func-types-unit-probe"
rm -f "${OUT}" "${PHP_COMPILER_JIT_PROGRESS_FILE}"

if ! php "${ROOT}/bin/compile.php" -o "${OUT}" "${ENTRY}" 2>&1; then
  echo "bootstrap-selfhost-types-unit-probe: compile failed (progress gate; see stderr above)" >&2
  exit 1
fi
test -x "${OUT}"

bundle_out="$("${OUT}")"
if ! grep -q 'types_unit_probe bundle OK' <<< "${bundle_out}"; then
  echo "bootstrap-selfhost-types-unit-probe: unexpected link stdout (want types_unit_probe bundle OK)" >&2
  printf '%s\n' "${bundle_out}" >&2
  exit 1
fi

echo "bootstrap-selfhost-types-unit-probe: OK ${OUT}"
