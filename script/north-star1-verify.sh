#!/usr/bin/env bash
# Example web integration verify (legacy make target north-star1-verify; #1044 closed, #1845).
#
#   ./script/north-star1-verify.sh
#   make north-star1-verify
#
# Order: doctor --gates → miniwebapp-gates → ci-fast MiniWebApp → AOT execute (LLVM)
#        → optional shell AOT web-smoke (LLVM + loopback).
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
Usage: script/north-star1-verify.sh [--require-llvm] [--skip-llvm-tail]

Runs example web integration checks in order (issue #1845; #1044 closed):

  1. phpc doctor --gates
  2. script/miniwebapp-gates.sh
  3. script/ci-fast.sh --filter 'MiniWebApp'
  4. MiniWebApp AOT execute PHPUnit (when LLVM 9 ready)
  5. examples-web-smoke --miniwebapp-only --aot (when LLVM ready; skip with --skip-llvm-tail)

Options:
  --require-llvm     fail if LLVM 9 is missing (default: skip LLVM steps)
  --skip-llvm-tail   skip step 5 only (step 4 still runs when LLVM ready)

Environment: same as ci-local (script/ci-defaults.env). See docs/miniwebapp-gates.md.

Docker:
  docker run --rm -v "$(pwd):/compiler" -w /compiler php-compiler:22.04-dev make north-star1-verify
EOF
      exit 0
      ;;
    *) echo "north-star1-verify: unknown argument: $1" >&2; exit 1 ;;
  esac
done

ns1_hint() {
  local step="$1"
  case "${step}" in
    1) echo "Next: fix env / phpc wrapper; see phpc doctor and #1752" ;;
    2) echo "Next: read miniwebapp-gates.sh \"Next:\" line; ladder #472, #1044" ;;
    3) echo "Next: ./script/ci-fast.sh --filter MiniWebApp; VM/serve #539 #641 #597" ;;
    4) echo "Next: ./script/ci-local.sh --filter MiniWebAppAotExecuteTest; LLVM #764 #747" ;;
    5) echo "Next: MINIWEBAPP_WEB_SMOKE_AOT_GATE=1 ./script/examples-web-smoke.sh --miniwebapp-only --aot; #1523" ;;
    *) echo "Next: see https://github.com/PurHur/php-compiler/issues/1044" ;;
  esac
}

ns1_run() {
  local step="$1"
  local label="$2"
  shift 2
  echo
  echo "=== north-star1-verify step ${step}: ${label} ==="
  if "$@"; then
    echo "north-star1-verify: step ${step} ok"
    return 0
  fi
  echo "north-star1-verify: step ${step} FAILED (${label})" >&2
  ns1_hint "${step}" >&2
  exit 1
}

if [[ ! -x "${_CI_REPO_ROOT}/phpc" ]]; then
  echo "north-star1-verify: phpc wrapper missing; run composer install" >&2
  exit 1
fi

ns1_run 1 "phpc doctor --gates" "${_CI_REPO_ROOT}/phpc" doctor --gates
ns1_run 2 "miniwebapp gate ladder" "${_CI_SCRIPT_DIR}/miniwebapp-gates.sh"
ns1_run 3 "ci-fast MiniWebApp subset" "${_CI_SCRIPT_DIR}/ci-fast.sh" --filter 'MiniWebApp'

if ! ci_llvm_ready; then
  if [[ "${REQUIRE_LLVM}" -eq 1 ]]; then
    echo "north-star1-verify: LLVM 9 required (--require-llvm) but not found at $(ci_llvm_dir)" >&2
    echo "Next: script/install-llvm9.sh or export PHP_COMPILER_LLVM_PATH" >&2
    exit 1
  fi
  echo
  echo "=== north-star1-verify: steps 4–5 skipped (LLVM 9 not available) ==="
  echo "north-star1-verify: all runnable steps passed"
  exit 0
fi

ci_prepare_test_runtime
ci_install_deps
ci_configure_serve_tests

export MINIWEBAPP_AOT_EXECUTE_GATE=1
ns1_run 4 "MiniWebApp AOT execute (PHPUnit)" ci_run_miniwebapp_aot_execute

if [[ "${SKIP_LLVM_TAIL}" -eq 1 ]]; then
  echo
  echo "north-star1-verify: step 5 skipped (--skip-llvm-tail)"
  echo "north-star1-verify: all steps passed"
  exit 0
fi

if [[ -n "${PHP_COMPILER_SKIP_SERVE_TESTS:-}" ]]; then
  echo
  echo "=== north-star1-verify: step 5 skipped (PHP_COMPILER_SKIP_SERVE_TESTS) ==="
  echo "north-star1-verify: all runnable steps passed"
  exit 0
fi

if ! ci_can_bind_loopback; then
  echo
  echo "=== north-star1-verify: step 5 skipped (cannot bind loopback TCP) ==="
  echo "north-star1-verify: all runnable steps passed"
  exit 0
fi

ns1_run 5 "examples-web-smoke 003 AOT HTTP" env MINIWEBAPP_WEB_SMOKE_AOT_GATE=1 \
  "${_CI_SCRIPT_DIR}/examples-web-smoke.sh" --miniwebapp-only --aot

echo
echo "north-star1-verify: all steps passed (#1044 presenter gate)"
