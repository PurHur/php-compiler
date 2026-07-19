#!/usr/bin/env bash
# Self-host compile probe with JIT progress on native segfault (issue #816).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# shellcheck source=ci-memory-env.sh
source "${ROOT}/script/ci-memory-env.sh"
PHP_OPTS=()
# Match north-star5-verify step 4a: apply LLVM compile heap before -l/-o (#21104).
ci_apply_llvm_memory_env
PROGRESS="${ROOT}/build/.last-jit-func"
export PHP_COMPILER_SELFHOST_AOT=1
export PHP_COMPILER_JIT_PROGRESS_FILE="${PROGRESS}"
mkdir -p "${ROOT}/build"
rm -f "${PROGRESS}"

set +e
# PHP_OPTS may carry -d memory_limit=… from ci_apply_llvm_memory_env.
# shellcheck disable=SC2086
php ${PHP_OPTS[@]+"${PHP_OPTS[@]}"} "${ROOT}/script/bootstrap-selfhost-compile-probe.php" "$@"
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
