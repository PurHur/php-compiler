#!/usr/bin/env bash
# M2 lib spine smoke AOT lint (issues #1056, #8391).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENTRY="${ROOT}/test/selfhost/compiler_lib_spine_smoke/main.php"
# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
ci_apply_llvm_memory_env
ci_ensure_vendor_patches

if [[ ! -f "${ENTRY}" ]]; then
  echo "bootstrap-selfhost-lib-spine-smoke-lint: missing ${ENTRY}" >&2
  exit 1
fi

"${PHP_BIN}" "${PHP_OPTS[@]}" "${ROOT}/bin/compile.php" -l "${ENTRY}"
echo "bootstrap-selfhost-lib-spine-smoke-lint: OK ${ENTRY}"
