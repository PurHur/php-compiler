#!/usr/bin/env bash
# Bundled Compiler.php AOT lint gate (issues #212, #78).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENTRY="${ROOT}/test/selfhost/compiler_minimal/main.php"
if [[ ! -f "${ENTRY}" ]]; then
  echo "bootstrap-selfhost-lint: missing ${ENTRY}" >&2
  exit 1
fi
php "${ROOT}/bin/compile.php" -l "${ENTRY}"
echo "bootstrap-selfhost-lint: OK ${ENTRY}"
