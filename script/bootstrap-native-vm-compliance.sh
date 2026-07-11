#!/usr/bin/env bash
# Curated VM compliance subset without PHPUnit (#15599 phase 1).
#
# Runs manifest entries via bin/vm.php (Zend host launches VM only — no PHPUnit harness).
#
# Usage:
#   ./script/bootstrap-native-vm-compliance.sh
#   phpc test --native
#
# Manifest: test/bootstrap-native/compliance-subset.manifest
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
MANIFEST="${ROOT}/test/bootstrap-native/compliance-subset.manifest"

# shellcheck source=php-env.sh
source "${ROOT}/script/php-env.sh"

if [[ ! -f "${MANIFEST}" ]]; then
  echo "bootstrap-native-vm-compliance: missing manifest ${MANIFEST}" >&2
  exit 1
fi

if [[ ! -x "${ROOT}/bin/vm.php" ]]; then
  echo "bootstrap-native-vm-compliance: bin/vm.php missing" >&2
  exit 1
fi

ran=0
while IFS= read -r line || [[ -n "${line}" ]]; do
  line="${line%%#*}"
  line="$(echo "${line}" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
  [[ -z "${line}" ]] && continue

  rel="${line%%|*}"
  expect=""
  if [[ "${line}" == *"|"* ]]; then
    expect="${line#*|}"
  fi
  rel="$(echo "${rel}" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
  expect="$(echo "${expect}" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"

  script="${ROOT}/${rel}"
  if [[ ! -f "${script}" ]]; then
    echo "bootstrap-native-vm-compliance: missing ${rel}" >&2
    exit 1
  fi

  set +e
  out="$(php "${ROOT}/bin/vm.php" "${script}" 2>&1)"
  code=$?
  set -e
  if [[ "${code}" -ne 0 ]]; then
    echo "bootstrap-native-vm-compliance: ${rel} exit ${code}" >&2
    printf '%s\n' "${out}" >&2
    exit 1
  fi
  if [[ -n "${expect}" ]] && ! grep -qF "${expect}" <<< "${out}"; then
    echo "bootstrap-native-vm-compliance: ${rel} missing expect substring: ${expect}" >&2
    printf '%s\n' "${out}" >&2
    exit 1
  fi
  echo "bootstrap-native-vm-compliance: OK ${rel}"
  ran=$((ran + 1))
done < "${MANIFEST}"

if [[ "${ran}" -eq 0 ]]; then
  echo "bootstrap-native-vm-compliance: empty manifest ${MANIFEST}" >&2
  exit 1
fi

echo "bootstrap-native-vm-compliance: OK (${ran} case(s))"
