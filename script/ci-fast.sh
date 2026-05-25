#!/usr/bin/env bash
# Fast local CI: VM/compliance + optional serve — no LLVM compile phases (issue #436).
#
# Use while iterating on compiler/VM changes. Before merge, run ./script/ci-local.sh
# (or: phpc test, make test) for the full JIT/AOT/link gate.
#
# Optional bootstrap tail (aot-lint + selfhost-probe + wave-check when LLVM ready):
#   CI_FAST_BOOTSTRAP=1 ./script/ci-fast.sh
set -euo pipefail

# shellcheck source=ci-common.sh
source "$(dirname "$0")/ci-common.sh"

ci_cd_repo
ci_prepare_test_runtime
ci_install_deps
ci_jit_preflight_gate
ci_run_inventory_checks
ci_report_llvm_status
ci_configure_serve_tests

echo "PHPUnit (fast): VM, compliance, real-world — excluding @group llvm,serve,cgi..."
ci_run_phpunit --exclude-group llvm,serve,cgi "$@"

echo "PHPUnit (fast): CGI driver (bin/cgi.php, no TCP, #656, #666)..."
ci_run_phpunit --filter CgiDriverTest "$@"

if [[ "${MINIWEBAPP_SERVE_GATE:-1}" == "1" && -n "${PHP_COMPILER_SKIP_SERVE_TESTS:-}" ]]; then
  echo "MINIWEBAPP_SERVE_GATE=1 (default) requires serve tests; unset PHP_COMPILER_SKIP_SERVE_TESTS (#622, #641)" >&2
  exit 1
fi

if [[ -z "${PHP_COMPILER_SKIP_SERVE_TESTS:-}" ]]; then
  serve_groups=(--group serve --exclude-group llvm)
  if [[ "${MINIWEBAPP_SERVE_GATE:-1}" == "1" ]]; then
    serve_groups+=(--exclude-group miniwebapp)
  fi
  echo "PHPUnit (fast): HTTP serve (bin/serve.php, no AOT compile)..."
  ci_run_phpunit "${serve_groups[@]}" "$@"

  if [[ "${MINIWEBAPP_SERVE_GATE:-1}" == "1" ]]; then
    echo "PHPUnit (fast): MiniWebApp ServeTest (MINIWEBAPP_SERVE_GATE=1 default, #470, #641)..."
    ci_run_phpunit --filter ServeTest --group miniwebapp --fail-on-skipped "$@"
  fi
fi

# Always lint 003-MiniWebApp even when callers pass --filter (issue #570, #539).
echo "PHPUnit (fast): MiniWebApp project lint (PhpcLintProjectTest)..."
ci_run_phpunit --filter PhpcLintProjectTest

# VM CLI route matrix without TCP (issues #586, #595, #597). Default on while green.
if [[ "${MINIWEBAPP_VM_CLI_GATE:-1}" == "1" ]]; then
  echo "PHPUnit (fast): MiniWebApp VM CLI gates (MiniWebApp*VmCli)..."
  ci_run_phpunit --filter 'MiniWebApp.*VmCli'
fi

# Nested return <call>() VM compliance (#1885, #1888). Default on; set NESTED_RETURN_COMPLIANCE_GATE=0 to skip.
if [[ "${NESTED_RETURN_COMPLIANCE_GATE:-1}" == "1" ]]; then
  echo "PHPUnit (fast): nested return VM compliance (NestedReturn*)..."
  ci_run_phpunit --filter NestedReturn
fi

# Optional bootstrap tail when LLVM 9 present (aot-lint + probe + wave-check; issue #436).
if [[ "${CI_FAST_BOOTSTRAP:-0}" == "1" ]]; then
  if ci_llvm_ready; then
    ci_apply_llvm_memory_env
    ci_run_bootstrap_aot_lint
    BOOTSTRAP_SELFHOST_PROBE_GATE="${BOOTSTRAP_SELFHOST_PROBE_GATE:-1}" ci_run_bootstrap_selfhost_probe
    echo "PHPUnit (fast+bootstrap): AOT lint (@group aot-lint)..."
    ci_run_phpunit --group aot-lint "$@"
    BOOTSTRAP_WAVE_CHECK="${BOOTSTRAP_WAVE_CHECK:-1}" ci_run_bootstrap_wave_check
  else
    echo "CI_FAST_BOOTSTRAP=1: bootstrap tail skipped (LLVM 9 not available)"
  fi
fi

echo "Fast CI finished. Full LLVM compile gate: ./script/ci-local.sh (issue #436)."
