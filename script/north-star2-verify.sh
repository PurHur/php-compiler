#!/usr/bin/env bash
# North Star 2 presenter verify — self-host M0–M4 ladder (issues #1865, #1492, #1056).
#
#   ./script/north-star2-verify.sh
#   make north-star2-verify
#
# Order: doctor --gates → inventory --check → wave gate → M0 link → M2 spine link/VM
#        → optional LLVM tail: M3 partial probes + M4 loop dry-run.
#
# Exits non-zero on the first failing step. LLVM steps are skipped when LLVM 9 is absent
# (exit 0 for those skips). Use --require-llvm to fail when LLVM is missing.
set -euo pipefail

# shellcheck source=ci-common.sh
source "$(dirname "$0")/ci-common.sh"

ci_cd_repo

REQUIRE_LLVM=0
SKIP_LLVM_TAIL=0
STRICT_M3=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --require-llvm) REQUIRE_LLVM=1; shift ;;
    --skip-llvm-tail) SKIP_LLVM_TAIL=1; shift ;;
    --strict) STRICT_M3=1; shift ;;
    -h|--help)
      cat <<'EOF'
Usage: script/north-star2-verify.sh [--require-llvm] [--skip-llvm-tail] [--strict]

Runs North Star 2 checks in order (issue #1865, epic #1492):

  1. phpc doctor --gates (North Star 2 section)
  2. php script/bootstrap-inventory.php --check
  3. make bootstrap-wave-check (M0–M2 probes)
  4. M0 selfhost link + M2 lib spine native link + VM -r smoke (when LLVM 9 ready)
  5. M3 HelloWorld + compile-smoke partial probes; M4 loop --dry-run (LLVM tail)

Options:
  --require-llvm     fail if LLVM 9 is missing (default: skip LLVM steps)
  --skip-llvm-tail   skip step 5 only (step 4 still runs when LLVM ready)
  --strict           run BOOTSTRAP_M3_*_STRICT=1 probes (may fail until #1493/#1937)

Environment: script/ci-defaults.env. See docs/bootstrap-selfhost.md.

Docker:
  docker run --rm -v "$(pwd):/compiler" -w /compiler php-compiler:22.04-dev make north-star2-verify
EOF
      exit 0
      ;;
    *) echo "north-star2-verify: unknown argument: $1" >&2; exit 1 ;;
  esac
done

ns2_hint() {
  local step="$1"
  case "${step}" in
    1) echo "Next: composer install; phpc doctor — see #1752" ;;
    2) echo "Next: php script/bootstrap-inventory.php; fix inventory blockers (#765)" ;;
    3) echo "Next: ./script/bootstrap-wave-check.sh --fail-fast; selfhost-lint (#816)" ;;
    4) echo "Next: make bootstrap-selfhost-link; BOOTSTRAP_LIB_SPINE_SMOKE=1 make bootstrap-selfhost-lib-spine-smoke (#1492)" ;;
    5) echo "Next: BOOTSTRAP_M3_HELLOWORLD_STRICT=1 ./script/bootstrap-selfhost-helloworld-probe.sh (#1493)" ;;
    *) echo "Next: see https://github.com/PurHur/php-compiler/issues/1492" ;;
  esac
}

ns2_run() {
  local step="$1"
  local label="$2"
  shift 2
  echo
  echo "=== north-star2-verify step ${step}: ${label} ==="
  if "$@"; then
    echo "north-star2-verify: step ${step} ok"
    return 0
  fi
  echo "north-star2-verify: step ${step} FAILED (${label})" >&2
  ns2_hint "${step}" >&2
  exit 1
}

if [[ ! -x "${_CI_REPO_ROOT}/phpc" ]]; then
  echo "north-star2-verify: phpc wrapper missing; run composer install" >&2
  exit 1
fi

ci_prepare_test_runtime
ci_install_deps

ns2_run 1 "phpc doctor --gates" "${_CI_REPO_ROOT}/phpc" doctor --gates
ns2_run 2 "bootstrap inventory --check" ci_ensure_generated_doc "${_CI_REPO_ROOT}/script/bootstrap-inventory.php" "${_CI_REPO_ROOT}/docs/bootstrap-inventory.md"

if ! ci_llvm_ready; then
  if [[ "${REQUIRE_LLVM}" -eq 1 ]]; then
    echo "north-star2-verify: LLVM 9 required (--require-llvm) but not found at $(ci_llvm_dir)" >&2
    echo "Next: script/install-llvm9.sh or export PHP_COMPILER_LLVM_PATH" >&2
    exit 1
  fi
  echo
  echo "=== north-star2-verify: steps 3–5 skipped (LLVM 9 not available) ==="
  echo "north-star2-verify: runnable steps passed (inventory + spine sync)"
  exit 0
fi

ci_apply_llvm_memory_env

ns2_run 3 "bootstrap wave gate" "${_CI_SCRIPT_DIR}/bootstrap-wave-check.sh" --fail-fast

ns2_run 4a "M0 selfhost native link" "${_CI_SCRIPT_DIR}/bootstrap-selfhost-link.sh"
export BOOTSTRAP_LIB_SPINE_SMOKE=1
ns2_run 4b "M2 lib spine native link" make bootstrap-selfhost-lib-spine-smoke
export BOOTSTRAP_LIB_SPINE_VM_SMOKE=1
ns2_run 4c "M2 lib spine VM smoke" "${_CI_SCRIPT_DIR}/bootstrap-selfhost-lib-spine-vm-smoke.sh"

if [[ "${SKIP_LLVM_TAIL}" -eq 1 ]]; then
  echo
  echo "north-star2-verify: step 5 skipped (--skip-llvm-tail)"
  echo "north-star2-verify: all steps passed (#1492 presenter)"
  exit 0
fi

echo
echo "=== north-star2-verify step 5: M3 partial probes + M4 loop dry-run ==="
if [[ "${STRICT_M3}" -eq 1 ]]; then
  export BOOTSTRAP_M3_HELLOWORLD_STRICT_GATE=1
  export BOOTSTRAP_M3_COMPILE_SMOKE_STRICT_GATE=1
  ci_run_bootstrap_m3_strict || {
    echo "north-star2-verify: M3 HelloWorld strict probe failed" >&2
    ns2_hint 5 >&2
    exit 1
  }
  ci_run_bootstrap_m3_compile_smoke_strict || {
    echo "north-star2-verify: M3 compile-smoke strict probe failed" >&2
    ns2_hint 5 >&2
    exit 1
  }
else
  BOOTSTRAP_M3_LINK_COMPILE_DRIVER=1 \
    BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING=1 \
    BOOTSTRAP_M3_RUNTIME_COMPILE=1 \
    "${_CI_SCRIPT_DIR}/bootstrap-selfhost-helloworld-probe.sh" || {
    echo "north-star2-verify: M3 HelloWorld partial probe failed" >&2
    ns2_hint 5 >&2
    exit 1
  }
  BOOTSTRAP_M3_LINK_COMPILE_DRIVER=1 \
    BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING=1 \
    BOOTSTRAP_M3_RUNTIME_COMPILE=1 \
    "${_CI_SCRIPT_DIR}/bootstrap-selfhost-compile-smoke-probe.sh" || {
    echo "north-star2-verify: M3 compile-smoke partial probe failed" >&2
    ns2_hint 5 >&2
    exit 1
  }
fi

if [[ -x "${_CI_SCRIPT_DIR}/bootstrap-loop-probe.sh" ]]; then
  BOOTSTRAP_LOOP_PROBE_GATE=1 "${_CI_SCRIPT_DIR}/bootstrap-loop-probe.sh" --dry-run || {
    echo "north-star2-verify: M4 loop dry-run failed" >&2
    ns2_hint 5 >&2
    exit 1
  }
else
  echo "north-star2-verify: bootstrap-loop-probe.sh missing — M4 dry-run skipped"
fi

echo "north-star2-verify: step 5 ok"
echo
echo "north-star2-verify: all steps passed (#1492 presenter gate)"
