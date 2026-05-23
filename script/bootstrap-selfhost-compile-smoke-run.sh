#!/usr/bin/env bash
# AOT link + execute compiler_smoke_standalone echo fixture (issues #212, #816 wave 7A).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENTRY="${ROOT}/test/bootstrap-aot/compiler_smoke_standalone.php"
OUT="${ROOT}/build/selfhost-compile-smoke-echo"
# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
ci_apply_llvm_memory_env

if [[ ! -f "${ENTRY}" ]]; then
  echo "bootstrap-selfhost-compile-smoke-run: missing ${ENTRY}" >&2
  exit 1
fi

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-selfhost-compile-smoke-run: LLVM 9 not found (skip)" >&2
  exit 2
fi

mkdir -p "${ROOT}/build"
rm -f "${OUT}"
if ! php "${ROOT}/bin/compile.php" -o "${OUT}" "${ENTRY}" 2>&1; then
  echo "bootstrap-selfhost-compile-smoke-run: compile failed (see stderr above)" >&2
  exit 1
fi
test -x "${OUT}"
out="$("${OUT}")"
if ! grep -q 'compiler smoke' <<< "${out}"; then
  echo "bootstrap-selfhost-compile-smoke-run: unexpected stdout (want compiler smoke)" >&2
  printf '%s\n' "${out}" >&2
  exit 1
fi
echo "bootstrap-selfhost-compile-smoke-run: OK ${OUT}"
