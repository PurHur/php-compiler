#!/usr/bin/env bash
# Bundled Compiler.php AOT native link + run gate (issues #212, #78).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENTRY="${ROOT}/test/selfhost/compiler_minimal/main.php"
OUT="${ROOT}/build/selfhost"
# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
ci_apply_llvm_memory_env

if [[ ! -f "${ENTRY}" ]]; then
  echo "bootstrap-selfhost-link: missing ${ENTRY}" >&2
  exit 1
fi

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-selfhost-link: LLVM 9 not found (skip)" >&2
  exit 2
fi

mkdir -p "${ROOT}/build"
rm -f "${OUT}"
php "${ROOT}/bin/compile.php" -o "${OUT}" "${ENTRY}"
test -x "${OUT}"
out="$("${OUT}")"
test "selfhost" = "${out//$'\n'/}"
echo "bootstrap-selfhost-link: OK ${OUT}"
