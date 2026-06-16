#!/usr/bin/env bash
# Wave gate: selfhost-lint → aot-lint (quick) → selfhost-probe; prints NEXT_LOWER.
# Optional: --with-compile-smoke adds compiler_compile_smoke native link + echo run.
# Optional: --with-helloworld adds M3 HelloWorld self-host probe (BOOTSTRAP_M3_HELLOWORLD=1).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
FAIL_FAST=0
VENDOR_ABSENT=0
WITH_COMPILE_SMOKE=0
WITH_LIB_SPINE_SMOKE=0
WITH_LIB_SPINE_VM_SMOKE=0
WITH_HELLOWORLD=0
WITH_DRIVER_SMOKE=0
if [[ "${BOOTSTRAP_WAVE_CHECK_VENDOR_ABSENT:-0}" == "1" ]]; then
  VENDOR_ABSENT=1
fi
if [[ "${BOOTSTRAP_LIB_SPINE_SMOKE:-0}" == "1" ]]; then
  WITH_LIB_SPINE_SMOKE=1
fi
if [[ "${BOOTSTRAP_LIB_SPINE_VM_SMOKE:-0}" == "1" ]]; then
  WITH_LIB_SPINE_VM_SMOKE=1
fi
if [[ "${BOOTSTRAP_M3_HELLOWORLD:-0}" == "1" ]]; then
  WITH_HELLOWORLD=1
fi
if [[ "${BOOTSTRAP_M5_DRIVER_SMOKE:-0}" == "1" ]]; then
  WITH_DRIVER_SMOKE=1
fi
while [[ $# -gt 0 ]]; do
  case "$1" in
    --fail-fast)
      FAIL_FAST=1
      ;;
    --vendor-absent)
      VENDOR_ABSENT=1
      ;;
    --with-compile-smoke)
      WITH_COMPILE_SMOKE=1
      ;;
    --with-lib-spine-smoke)
      WITH_LIB_SPINE_SMOKE=1
      ;;
    --with-lib-spine-vm-smoke)
      WITH_LIB_SPINE_VM_SMOKE=1
      ;;
    --with-helloworld)
      WITH_HELLOWORLD=1
      ;;
    --with-driver-smoke)
      WITH_DRIVER_SMOKE=1
      ;;
    *)
      echo "bootstrap-wave-check: unknown argument: $1" >&2
      exit 1
      ;;
  esac
  shift
done

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

wave_check_prelinked_vendor_ready() {
  local manifest="${ROOT}/prelinked/bootstrap-vendor/manifest.json"
  local slug o ok=0
  [[ -f "${manifest}" ]] || return 1
  for slug in ircmaxell-php-cfg ircmaxell-php-types ircmaxell-php-llvm; do
    o="${ROOT}/prelinked/bootstrap-vendor/${slug}.o"
    [[ -f "${o}" ]] && ok=$((ok + 1))
  done
  [[ "${ok}" -eq 3 ]]
}

wave_check_restore_vendor() {
  if [[ -n "${WAVE_CHECK_VENDOR_BAK:-}" && -d "${WAVE_CHECK_VENDOR_BAK}" ]]; then
    mv "${WAVE_CHECK_VENDOR_BAK}" "${ROOT}/vendor"
    WAVE_CHECK_VENDOR_BAK=""
  fi
}

wave_check_cleanup() {
  wave_check_restore_vendor
  rm -f "${PROBE_OUT:-}"
}

run_vendor_absent_wave_slice() {
  if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
    echo "bootstrap-wave-check: vendor-absent slice skipped (LLVM 9 not available — exit 2)"
    exit 2
  fi
  if ! wave_check_prelinked_vendor_ready; then
    echo "bootstrap-wave-check: vendor-absent slice skipped (prelinked/bootstrap-vendor/*.o missing — exit 2)"
    exit 2
  fi
  WAVE_CHECK_VENDOR_BAK=""
  if [[ -d "${ROOT}/vendor" ]]; then
    WAVE_CHECK_VENDOR_BAK="${ROOT}/.wave-check-vendor.bak"
    rm -rf "${WAVE_CHECK_VENDOR_BAK}"
    mv "${ROOT}/vendor" "${WAVE_CHECK_VENDOR_BAK}"
  fi
  run_step "selfhost-lib-spine-smoke (vendor absent)" \
    env VENDOR_TREE_ABSENT=1 BOOTSTRAP_NO_ZEND_FALLBACK=1 \
    ./script/bootstrap-selfhost-lib-spine-smoke-link.sh
  print_summary
  for code in "${STEP_CODES[@]}"; do
    if [[ "${code}" -ne 0 && "${code}" -ne 2 ]]; then
      exit "${code}"
    fi
  done
  exit 0
}

if [[ "${VENDOR_ABSENT}" -eq 1 ]]; then
  PROBE_OUT=""
  trap wave_check_cleanup EXIT
  run_vendor_absent_wave_slice
fi

PROBE_OUT="$(mktemp)"
trap wave_check_cleanup EXIT

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

if [[ "${WITH_COMPILE_SMOKE}" -eq 1 ]]; then
  run_step "selfhost-compile-smoke" ./script/bootstrap-selfhost-compile-smoke-link.sh
  run_step "selfhost-compile-smoke-echo" ./script/bootstrap-selfhost-compile-smoke-run.sh
  print_summary
fi

if [[ "${WITH_LIB_SPINE_SMOKE}" -eq 1 ]]; then
  run_step "selfhost-lib-spine-smoke" ./script/bootstrap-selfhost-lib-spine-smoke-link.sh
  print_summary
fi

if [[ "${WITH_LIB_SPINE_VM_SMOKE}" -eq 1 ]]; then
  run_step "selfhost-lib-spine-vm-smoke" ./script/bootstrap-selfhost-lib-spine-vm-smoke.sh
  print_summary
fi

if [[ "${WITH_HELLOWORLD}" -eq 1 ]]; then
  run_step "selfhost-helloworld-probe" ./script/bootstrap-selfhost-helloworld-probe.sh
  print_summary
fi

if [[ "${WITH_DRIVER_SMOKE}" -eq 1 ]]; then
  run_step "selfhost-driver-smoke" ./script/bootstrap-selfhost-driver-smoke.sh
  print_summary
fi

if [[ "${BOOTSTRAP_VENDOR_PRELINK_GATE:-0}" == "1" ]]; then
  run_step "vendor-prelink-bundles" php script/bootstrap-vendor-objects.php --check
  run_step "vendor-prelink-compile" make bootstrap-vendor-objects
  print_summary
fi

if [[ "${NATIVE_COMPILE_DRIVER_GATE:-0}" == "1" ]]; then
  run_step "native-compile-driver-smoke" ./script/bootstrap-native-compile-driver-smoke.sh
  print_summary
fi

for code in "${STEP_CODES[@]}"; do
  if [[ "${code}" -ne 0 && "${code}" -ne 2 ]]; then
    exit "${code}"
  fi
done
exit 0
