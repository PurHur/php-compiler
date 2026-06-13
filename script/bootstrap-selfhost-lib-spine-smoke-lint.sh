#!/usr/bin/env bash
# M2 lib spine smoke AOT lint gate (issue #8391, #1492).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# shellcheck source=script/ci-memory-env.sh
source "${ROOT}/script/ci-memory-env.sh"
ci_apply_llvm_memory_env

ENTRY="${ROOT}/test/selfhost/compiler_lib_spine_smoke/main.php"
if [[ ! -f "${ENTRY}" ]]; then
  echo "bootstrap-selfhost-lib-spine-smoke-lint: missing ${ENTRY}" >&2
  exit 1
fi

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-selfhost-lib-spine-smoke-lint: LLVM 9 not found (skip)" >&2
  exit 2
fi

php "${ROOT}/bin/compile.php" -l "${ENTRY}"
echo "bootstrap-selfhost-lib-spine-smoke-lint: OK ${ENTRY}"
