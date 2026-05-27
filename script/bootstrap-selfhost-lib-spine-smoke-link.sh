#!/usr/bin/env bash
# M2 lib spine smoke: bundled vm.php-path lib/ closure AOT native link + run (issues #1056, #1025).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENTRY="${ROOT}/test/selfhost/compiler_lib_spine_smoke/main.php"
OUT="${ROOT}/build/selfhost-lib-spine-smoke"
# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
# shellcheck source=bootstrap-resolve-compile-invoke.sh
source "$(dirname "$0")/bootstrap-resolve-compile-invoke.sh"
ci_apply_llvm_memory_env

if [[ ! -f "${ENTRY}" ]]; then
  echo "bootstrap-selfhost-lib-spine-smoke-link: missing ${ENTRY}" >&2
  exit 1
fi

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-selfhost-lib-spine-smoke-link: LLVM 9 not found (skip)" >&2
  exit 2
fi

mkdir -p "${ROOT}/build"
export PHP_COMPILER_SELFHOST_AOT=1
export PHP_COMPILER_JIT_PROGRESS_FILE="${ROOT}/build/.last-jit-func-lib-spine-smoke"
rm -f "${OUT}" "${PHP_COMPILER_JIT_PROGRESS_FILE}"
if ! bootstrap_compile_invoke "${OUT}" "${ENTRY}" env PHP_COMPILER_SELFHOST_AOT=1 2>&1; then
  echo "bootstrap-selfhost-lib-spine-smoke-link: compile failed (progress gate; see stderr above)" >&2
  exit 1
fi
test -x "${OUT}"
out="$({ PHP_COMPILER_CLI_SPINE_BUNDLE=1 "${OUT}"; })"
if ! grep -q 'compiler_lib_spine_smoke bundle OK' <<< "${out}"; then
  echo "bootstrap-selfhost-lib-spine-smoke-link: unexpected stdout (want compiler_lib_spine_smoke bundle OK)" >&2
  printf '%s\n' "${out}" >&2
  exit 1
fi
echo "bootstrap-selfhost-lib-spine-smoke-link: OK ${OUT}"
