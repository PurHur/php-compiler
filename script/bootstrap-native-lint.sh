#!/usr/bin/env bash
# Native lint via gen-2 inventory argv driver -l (#15601).
#
# Prefers build/bin-compile-aot-inventory -l (same parse/compile path as Tier 1 compile).
# Falls back to Zend php bin/compile.php -l when the gen-2 driver is missing.
#
# Usage:
#   ./script/bootstrap-native-lint.sh FILE.php
#   phpc lint --native FILE.php
#
# See docs/bootstrap-dev-workflow.md
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "${ROOT}"

if [[ $# -ne 1 ]]; then
  echo "Usage: script/bootstrap-native-lint.sh FILE.php" >&2
  exit 1
fi

FILE="${1}"
if [[ ! -f "${FILE}" ]]; then
  echo "bootstrap-native-lint: not a file: ${FILE}" >&2
  exit 1
fi

# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"

resolve_file() {
  local path="${1}"
  if [[ "${path}" == /* ]]; then
    printf '%s\n' "${path}"
    return 0
  fi
  local resolved
  resolved="$(realpath "${path}" 2>/dev/null || true)"
  if [[ -n "${resolved}" && -f "${resolved}" ]]; then
    printf '%s\n' "${resolved}"
    return 0
  fi
  printf '%s\n' "${ROOT}/${path}"
}

FILE="$(resolve_file "${FILE}")"
if [[ ! -f "${FILE}" ]]; then
  echo "bootstrap-native-lint: not a file: ${FILE}" >&2
  exit 1
fi

DRIVER="${ROOT}/build/bin-compile-aot-inventory"
if [[ -x "${DRIVER}" ]]; then
  "${DRIVER}" -l "${FILE}"
  echo "bootstrap-native-lint: OK (gen-2 driver) ${FILE}"
  exit 0
fi

echo "bootstrap-native-lint: ${DRIVER} missing — fallback to Zend php bin/compile.php -l (#15601)" >&2
"${PHP_BIN}" "${PHP_OPTS[@]}" "${ROOT}/bin/compile.php" -l "${FILE}"
echo "bootstrap-native-lint: OK (Zend fallback) ${FILE}"
