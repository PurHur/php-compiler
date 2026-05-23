#!/usr/bin/env bash
# Self-host compile probe with JIT progress on native segfault (issue #816).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PROGRESS="${ROOT}/build/.last-jit-func"
export PHP_COMPILER_SELFHOST_AOT=1
export PHP_COMPILER_JIT_PROGRESS_FILE="${PROGRESS}"
mkdir -p "${ROOT}/build"
rm -f "${PROGRESS}"

set +e
php "${ROOT}/script/bootstrap-selfhost-compile-probe.php" "$@"
code=$?
set -e

if [[ "${code}" -eq 139 ]]; then
  if [[ -f "${PROGRESS}" ]]; then
    last="$(tr -d '\n' < "${PROGRESS}")"
    if [[ -n "${last}" ]]; then
      echo "LAST_JIT_FUNC: ${last}"
    fi
  fi
fi
exit "${code}"
