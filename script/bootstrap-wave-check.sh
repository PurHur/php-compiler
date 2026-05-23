#!/usr/bin/env bash
# Wave gate: selfhost-lint → aot-lint (quick) → selfhost-probe; prints NEXT_LOWER.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
FAIL_FAST=0
if [[ "${1:-}" == "--fail-fast" ]]; then
  FAIL_FAST=1
  shift
fi

declare -a STEP_NAMES=()
declare -a STEP_CODES=()

run_step() {
  local name="$1"
  shift
  echo ""
  echo "==> ${name}"
  set +e
  (cd "${ROOT}" && "$@")
  local code=$?
  set -e
  echo "exit: ${code}"
  STEP_NAMES+=("${name}")
  STEP_CODES+=("${code}")
  if [[ "${FAIL_FAST}" -eq 1 && "${code}" -ne 0 ]]; then
    print_summary
    exit "${code}"
  fi
}

print_summary() {
  echo ""
  echo "=== wave-check summary ==="
  local i
  for i in "${!STEP_NAMES[@]}"; do
    echo "${STEP_NAMES[$i]}: exit ${STEP_CODES[$i]}"
  done
}

PROBE_OUT="$(mktemp)"
trap 'rm -f "${PROBE_OUT}"' EXIT

run_step "selfhost-lint" ./script/bootstrap-selfhost-lint.sh
run_step "aot-lint" php script/bootstrap-aot-lint.php

set +e
(cd "${ROOT}" && ./script/bootstrap-selfhost-compile-probe.sh) 2>&1 | tee "${PROBE_OUT}"
PROBE_CODE=${PIPESTATUS[0]}
set -e
echo "exit: ${PROBE_CODE}"
STEP_NAMES+=("selfhost-probe")
STEP_CODES+=("${PROBE_CODE}")

NEXT_LOWER=""
if grep -q '^NEXT_LOWER:' "${PROBE_OUT}"; then
  NEXT_LOWER="$(grep '^NEXT_LOWER:' "${PROBE_OUT}" | tail -1 | sed 's/^NEXT_LOWER: //')"
elif grep -q '^LAST_JIT_FUNC:' "${PROBE_OUT}"; then
  LAST="$(grep '^LAST_JIT_FUNC:' "${PROBE_OUT}" | tail -1 | sed 's/^LAST_JIT_FUNC: //')"
  NEXT_LOWER="LLVM segfault (last JIT: ${LAST})"
elif [[ "${PROBE_CODE}" -eq 0 ]]; then
  NEXT_LOWER="(none — probe OK)"
else
  NEXT_LOWER="selfhost-probe failed (exit ${PROBE_CODE})"
fi

print_summary
echo "NEXT_LOWER: ${NEXT_LOWER}"

for code in "${STEP_CODES[@]}"; do
  if [[ "${code}" -ne 0 && "${code}" -ne 2 ]]; then
    exit "${code}"
  fi
done
exit 0
