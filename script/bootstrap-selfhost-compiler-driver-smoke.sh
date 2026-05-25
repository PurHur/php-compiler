#!/usr/bin/env bash
# M3 compiler driver smoke: bundled Compiler + compile_driver closure AOT native link + run (issues #2136, #1025).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENTRY="${ROOT}/test/selfhost/compiler_driver_smoke/main.php"
OUT="${ROOT}/build/selfhost-compiler-driver-smoke"
# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
ci_apply_llvm_memory_env

if [[ ! -f "${ENTRY}" ]]; then
  echo "bootstrap-selfhost-compiler-driver-smoke: missing ${ENTRY}" >&2
  exit 1
fi

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-selfhost-compiler-driver-smoke: LLVM 9 not found (skip)" >&2
  exit 2
fi

mkdir -p "${ROOT}/build"
export PHP_COMPILER_SELFHOST_AOT=1
export PHP_COMPILER_JIT_PROGRESS_FILE="${ROOT}/build/.last-jit-func-compiler-driver-smoke"
rm -f "${OUT}" "${PHP_COMPILER_JIT_PROGRESS_FILE}"
if ! php "${ROOT}/bin/compile.php" -o "${OUT}" "${ENTRY}" 2>&1; then
  echo "bootstrap-selfhost-compiler-driver-smoke: compile failed (progress gate; see stderr above)" >&2
  exit 1
fi
test -x "${OUT}"
out="$("${OUT}")"
if ! grep -q 'compiler_driver_smoke bundle OK' <<< "${out}"; then
  echo "bootstrap-selfhost-compiler-driver-smoke: unexpected stdout (want compiler_driver_smoke bundle OK)" >&2
  printf '%s\n' "${out}" >&2
  exit 1
fi
echo "bootstrap-selfhost-compiler-driver-smoke: OK ${OUT}"
