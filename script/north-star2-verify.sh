#!/usr/bin/env bash
# North Star 2 presenter verify — single command for self-host M0–M4 sanity (issue #1865).
#
#   ./script/north-star2-verify.sh
#   make north-star2-verify
#
# Order: doctor --gates → inventory --check → bootstrap-wave-check → M2 spine link + VM smoke (LLVM)
#        → optional M3 HelloWorld probe + M4 loop --dry-run (LLVM tail).
#
# Exits non-zero on the first failing step. LLVM-only steps are skipped when .llvm
# is missing (exit 0 for those skips). Use --require-llvm to fail when LLVM is absent.
set -euo pipefail

# shellcheck source=ci-common.sh
source "$(dirname "$0")/ci-common.sh"

ci_cd_repo

REQUIRE_LLVM=0
SKIP_LLVM_TAIL=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --require-llvm) REQUIRE_LLVM=1; shift ;;
    --skip-llvm-tail) SKIP_LLVM_TAIL=1; shift ;;
    -h|--help)
      cat <<'EOF'
Usage: script/north-star2-verify.sh [--require-llvm] [--skip-llvm-tail]

Runs North Star 2 self-host checks in order (issue #1865, tracker #1492):

  1. phpc doctor --gates
  2. php script/bootstrap-inventory.php --check
  3. script/bootstrap-wave-check.sh (M0–M2 wave gate)
  4. M2 lib spine native link + VM -r smoke (when LLVM 9 ready)
  5. M3 HelloWorld probe + bootstrap-loop-probe --dry-run (when LLVM ready; skip with --skip-llvm-tail)

Options:
  --require-llvm     fail if LLVM 9 is missing (default: skip LLVM steps)
  --skip-llvm-tail   skip step 5 only (step 4 still runs when LLVM ready)

Environment: same as ci-local (script/ci-defaults.env). See docs/bootstrap-selfhost.md.

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
    1) echo "Next: fix env / phpc wrapper; see phpc doctor --gates and #1871" ;;
    2) echo "Next: php script/bootstrap-inventory.php; regenerate with make bootstrap-profile (#765)" ;;
    3) echo "Next: make bootstrap-wave-check or ./script/bootstrap-wave-check.sh; M0–M2 (#1492)" ;;
    4) echo "Next: BOOTSTRAP_LIB_SPINE_SMOKE=1 make bootstrap-selfhost-lib-spine-smoke; VM #1846" ;;
    5) echo "Next: make bootstrap-selfhost-helloworld; BOOTSTRAP_LOOP_PROBE_GATE=1 ./script/bootstrap-loop-probe.sh --dry-run (#1498)" ;;
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

ns2_run 1 "phpc doctor --gates" "${_CI_REPO_ROOT}/phpc" doctor --gates
ns2_run 2 "bootstrap inventory --check" ci_ensure_generated_doc script/bootstrap-inventory.php docs/bootstrap-inventory.md

ci_prepare_test_runtime
ci_install_deps

ns2_run 3 "bootstrap wave-check (M0–M2)" "${_CI_SCRIPT_DIR}/bootstrap-wave-check.sh"

if ! ci_llvm_ready; then
  if [[ "${REQUIRE_LLVM}" -eq 1 ]]; then
    echo "north-star2-verify: LLVM 9 required (--require-llvm) but not found at $(ci_llvm_dir)" >&2
    echo "Next: script/install-llvm9.sh or export PHP_COMPILER_LLVM_PATH" >&2
    exit 1
  fi
  echo
  echo "=== north-star2-verify: steps 4–5 skipped (LLVM 9 not available) ==="
  echo "north-star2-verify: all runnable steps passed"
  exit 0
fi

ns2_run 4 "M2 lib spine link + VM smoke" bash -c '
  set -euo pipefail
  export BOOTSTRAP_LIB_SPINE_SMOKE=1
  make -C "'"${_CI_REPO_ROOT}"'" bootstrap-selfhost-lib-spine-smoke
  export BOOTSTRAP_LIB_SPINE_VM_SMOKE=1
  make -C "'"${_CI_REPO_ROOT}"'" bootstrap-selfhost-lib-spine-vm-smoke
'

if [[ "${SKIP_LLVM_TAIL}" -eq 1 ]]; then
  echo
  echo "north-star2-verify: step 5 skipped (--skip-llvm-tail)"
  echo "north-star2-verify: all steps passed"
  exit 0
fi

ns2_run 5a "M3 HelloWorld self-host probe" "${_CI_SCRIPT_DIR}/bootstrap-selfhost-helloworld-probe.sh"
ns2_run 5b "M4 bootstrap-loop dry-run" "${_CI_SCRIPT_DIR}/bootstrap-loop-probe.sh" --dry-run

echo
echo "north-star2-verify: all steps passed (#1492 presenter gate)"
