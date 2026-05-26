#!/usr/bin/env bash
# North Star 3 presenter verify — M3 native unit probe bundle (issues #2360, #1492).
#
#   ./script/north-star3-verify.sh
#   make north-star3-verify
#
# Order: 008-SelfHostProbe VM run → compiler / JIT / VM unit probes (when scripts exist).
# Exits non-zero on the first failing step. LLVM-dependent probes skip when LLVM 9 is absent.
set -euo pipefail

# shellcheck source=ci-common.sh
source "$(dirname "$0")/ci-common.sh"

ci_cd_repo

REQUIRE_LLVM=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --require-llvm) REQUIRE_LLVM=1; shift ;;
    -h|--help)
      cat <<'EOF'
Usage: script/north-star3-verify.sh [--require-llvm]

Runs North Star 3 M3 unit-probe bundle (issue #2360, epic #1492):

  1. ./phpc run examples/008-SelfHostProbe/example.php (north-star2 presenter smoke)
     Optional when SELFHOSTPROBE_AOT_SMOKE_GATE=1: 008 AOT via examples-aot-smoke (#2407)
  2. BOOTSTRAP_COMPILER_UNIT_PROBE_GATE=1 bootstrap-selfhost-compiler-unit-probe.sh (#2216)
  3. BOOTSTRAP_JIT_UNIT_PROBE_GATE=1 bootstrap-selfhost-jit-unit-probe.sh (#2332)
  4. BOOTSTRAP_VM_UNIT_PROBE_GATE=1 bootstrap-selfhost-vm-unit-probe.sh (#2354)
  5. BOOTSTRAP_PARSER_UNIT_PROBE_GATE=1 bootstrap-selfhost-parser-unit-probe.sh (#2409, #2418)
  6. BOOTSTRAP_PHPTYPES_UNIT_PROBE_GATE=1 bootstrap-selfhost-types-unit-probe.sh (#2430, #2434)

Steps 2–6 require LLVM 9 and skip with a message when absent or when probe scripts are missing.
Use --require-llvm to fail if LLVM is missing (default: skip LLVM probes, exit 0).

Environment: script/ci-defaults.env. See docs/bootstrap-selfhost.md · docs/self-host-target.md.

Docker:
  ./script/docker-exec.sh -- bash -lc 'make north-star3-verify'
EOF
      exit 0
      ;;
    *) echo "north-star3-verify: unknown argument: $1" >&2; exit 1 ;;
  esac
done

ns3_hint() {
  local step="$1"
  case "${step}" in
    1) echo "Next: composer install; ./phpc run examples/008-SelfHostProbe/example.php (#2207)" ;;
    2) echo "Next: bootstrap-selfhost-compiler-unit-probe.sh (#2216)" ;;
    3) echo "Next: bootstrap-selfhost-jit-unit-probe.sh (#2332)" ;;
    4) echo "Next: bootstrap-selfhost-vm-unit-probe.sh (#2354)" ;;
    5) echo "Next: bootstrap-selfhost-parser-unit-probe.sh (#2409, #2418)" ;;
    6) echo "Next: bootstrap-selfhost-types-unit-probe.sh (#2430, #2434)" ;;
    *) echo "Next: see https://github.com/PurHur/php-compiler/issues/2360" ;;
  esac
}

ns3_run() {
  local step="$1"
  local label="$2"
  shift 2
  echo
  echo "=== north-star3-verify step ${step}: ${label} ==="
  if "$@"; then
    echo "north-star3-verify: step ${step} ok"
    return 0
  fi
  echo "north-star3-verify: step ${step} FAILED (${label})" >&2
  ns3_hint "${step}" >&2
  exit 1
}

ns3_run_unit_probe() {
  local step="$1"
  local label="$2"
  local script_name="$3"
  local gate_var="$4"
  local issue_ref="$5"
  local script="${_CI_SCRIPT_DIR}/${script_name}"

  if ! ci_llvm_ready; then
    echo
    echo "=== north-star3-verify: step ${step} skipped (LLVM 9 not available) ==="
    return 0
  fi

  if [[ ! -x "${script}" ]]; then
    echo
    echo "=== north-star3-verify: step ${step} skipped (${script_name} missing — ${issue_ref}) ==="
    return 0
  fi

  ns3_run "${step}" "${label}" env "${gate_var}=1" "${script}"
}

if [[ ! -x "${_CI_REPO_ROOT}/phpc" ]]; then
  echo "north-star3-verify: phpc wrapper missing; run composer install" >&2
  exit 1
fi

ci_prepare_test_runtime
ci_install_deps

EXAMPLE="${_CI_REPO_ROOT}/examples/008-SelfHostProbe/example.php"
if [[ ! -f "${EXAMPLE}" ]]; then
  echo "north-star3-verify: step 1 FAILED (008-SelfHostProbe missing)" >&2
  exit 1
fi

ns3_run 1 "008-SelfHostProbe VM run" "${_CI_REPO_ROOT}/phpc" run "${EXAMPLE}"

if [[ "${SELFHOSTPROBE_AOT_SMOKE_GATE:-1}" == "1" ]] && ci_llvm_ready; then
  ci_apply_llvm_memory_env
  ns3_run 1 "008-SelfHostProbe AOT execute (#2407)" env \
    SELFHOSTPROBE_AOT_SMOKE_GATE=1 EXAMPLES_AOT_SMOKE_ONLY=008 \
    "${_CI_SCRIPT_DIR}/examples-aot-smoke.sh"
elif [[ "${SELFHOSTPROBE_AOT_SMOKE_GATE:-1}" == "1" ]]; then
  echo
  echo "=== north-star3-verify: 008-SelfHostProbe AOT skipped (LLVM 9 not available; SELFHOSTPROBE_AOT_SMOKE_GATE=1) ==="
fi

if ! ci_llvm_ready; then
  if [[ "${REQUIRE_LLVM}" -eq 1 ]]; then
    echo "north-star3-verify: LLVM 9 required (--require-llvm) but not found at $(ci_llvm_dir)" >&2
    echo "Next: script/install-llvm9.sh or export PHP_COMPILER_LLVM_PATH" >&2
    exit 1
  fi
  echo
  echo "=== north-star3-verify: steps 2–6 skipped (LLVM 9 not available) ==="
  echo "north-star3-verify: OK"
  exit 0
fi

ci_apply_llvm_memory_env

ns3_run_unit_probe 2 "Compiler CFG + driver unit probe" \
  "bootstrap-selfhost-compiler-unit-probe.sh" \
  "BOOTSTRAP_COMPILER_UNIT_PROBE_GATE" "#2216"

ns3_run_unit_probe 3 "JIT codegen unit probe" \
  "bootstrap-selfhost-jit-unit-probe.sh" \
  "BOOTSTRAP_JIT_UNIT_PROBE_GATE" "#2332"

ns3_run_unit_probe 4 "VM interpreter unit probe" \
  "bootstrap-selfhost-vm-unit-probe.sh" \
  "BOOTSTRAP_VM_UNIT_PROBE_GATE" "#2354"

ns3_run_unit_probe 5 "CFG parse front-end unit probe" \
  "bootstrap-selfhost-parser-unit-probe.sh" \
  "BOOTSTRAP_PARSER_UNIT_PROBE_GATE" "#2409, #2418"

ns3_run_unit_probe 6 "PHPTypes coercion unit probe" \
  "bootstrap-selfhost-types-unit-probe.sh" \
  "BOOTSTRAP_PHPTYPES_UNIT_PROBE_GATE" "#2430, #2434"

echo
echo "north-star3-verify: OK"
