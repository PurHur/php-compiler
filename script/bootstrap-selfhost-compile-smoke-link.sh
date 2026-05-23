#!/usr/bin/env bash
# Bundled compiler_compile_smoke AOT native link + run gate (issues #212, #816).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENTRY="${ROOT}/test/selfhost/compiler_compile_smoke/main.php"
OUT="${ROOT}/build/selfhost-compile-smoke"
# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
ci_apply_llvm_memory_env

if [[ ! -f "${ENTRY}" ]]; then
  echo "bootstrap-selfhost-compile-smoke-link: missing ${ENTRY}" >&2
  exit 1
fi

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-selfhost-compile-smoke-link: LLVM 9 not found (skip)" >&2
  exit 2
fi

mkdir -p "${ROOT}/build"
export PHP_COMPILER_SELFHOST_AOT=1
export PHP_COMPILER_JIT_PROGRESS_FILE="${ROOT}/build/.last-jit-func-compile-smoke"
rm -f "${OUT}" "${PHP_COMPILER_JIT_PROGRESS_FILE}"
if ! php "${ROOT}/bin/compile.php" -o "${OUT}" "${ENTRY}" 2>&1; then
  echo "bootstrap-selfhost-compile-smoke-link: compile failed (progress gate; see stderr above)" >&2
  exit 1
fi
test -x "${OUT}"
out="$("${OUT}")"
if ! grep -q 'compiler_compile_smoke bundle OK' <<< "${out}"; then
  echo "bootstrap-selfhost-compile-smoke-link: unexpected stdout (want compiler_compile_smoke bundle OK)" >&2
  printf '%s\n' "${out}" >&2
  exit 1
fi
echo "bootstrap-selfhost-compile-smoke-link: OK ${OUT}"
