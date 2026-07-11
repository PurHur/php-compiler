#!/usr/bin/env bash
# Native test harness — fast compliance subset without Zend PHPUnit (#15599).
#
# Tier 1.5 orchestrator:
#   1. Native AOT compile+run smoke (gen-0/gen-2 driver)
#   2. Self-hosted VM driver execute probe (~20ms when prelinked)
#   3. Curated bin/vm.php compliance manifest (no PHPUnit)
#
# Usage:
#   ./script/bootstrap-native-test-subset.sh
#   phpc test --native
#   make bootstrap-native-test-subset
#
# LLVM missing: AOT tail skipped (exit 2 from child); VM compliance still runs.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"

run_step() {
  local label=$1
  shift
  echo "bootstrap-native-test-subset: ${label}..."
  if ! "$@"; then
    echo "bootstrap-native-test-subset: failed at ${label}" >&2
    exit 1
  fi
}

run_step "vm-compliance" "${ROOT}/script/bootstrap-native-vm-compliance.sh"

aot_code=0
set +e
"${ROOT}/script/bootstrap-native-test.sh"
aot_code=$?
set -e
if [[ "${aot_code}" -eq 0 ]]; then
  :
elif [[ "${aot_code}" -eq 2 ]]; then
  echo "bootstrap-native-test-subset: native AOT smoke skipped (LLVM 9 unavailable)"
else
  echo "bootstrap-native-test-subset: native AOT smoke failed (exit ${aot_code})" >&2
  exit 1
fi

vm_probe_code=0
set +e
"${ROOT}/script/bootstrap-selfhost-vm-driver-execute-probe.sh"
vm_probe_code=$?
set -e
if [[ "${vm_probe_code}" -eq 0 ]]; then
  :
elif [[ "${vm_probe_code}" -eq 2 ]]; then
  echo "bootstrap-native-test-subset: VM driver probe skipped (LLVM 9 unavailable)"
else
  echo "bootstrap-native-test-subset: VM driver probe failed (exit ${vm_probe_code})" >&2
  exit 1
fi

echo "bootstrap-native-test-subset: ok"
