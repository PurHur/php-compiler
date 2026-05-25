#!/usr/bin/env bash
# Fast local CI: VM/compliance + optional serve — no LLVM compile phases (issue #436).
#
# Use while iterating on compiler/VM changes. Before merge, run ./script/ci-local.sh
# (or: phpc test, make test) for the full JIT/AOT/link gate.
#
# Optional bootstrap tail (aot-lint + selfhost-probe + wave-check when LLVM ready):
#   CI_FAST_BOOTSTRAP=1 ./script/ci-fast.sh
# M4 bootstrap-loop dry-run when LLVM ready (default off; issue #1929):
#   BOOTSTRAP_LOOP_PROBE_GATE=1 ./script/ci-fast.sh
# Self-host presenter (default on; issue #1928, #2051). Opt-out:
#   NORTH_STAR2_VERIFY_GATE=0 ./script/ci-fast.sh
# Development status page sync (default on; issue #2083). Opt-out:
#   DEVELOPMENT_STATUS_SYNC_GATE=0 ./script/ci-fast.sh
# Bootstrap test subset (opt-in; issue #2069):
#   BOOTSTRAP_TEST_SUBSET_GATE=1 ./script/ci-fast.sh
#   BOOTSTRAP_TEST_SUBSET_STRICT=1 for M3 strict tail when LLVM ready
set -euo pipefail

# shellcheck source=ci-common.sh
source "$(dirname "$0")/ci-common.sh"

ci_cd_repo
ci_prepare_test_runtime
ci_install_deps
ci_jit_preflight_gate
ci_run_inventory_checks
ci_run_bootstrap_test_subset
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

  ci_run_sessions_web_smoke
  ci_run_file_upload_web_smoke
  ci_run_throws_web_smoke
  ci_run_throws_web_uncaught_smoke
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

# PHP 8 attributes VM compliance (#1354, #1904). Default on; set ATTRIBUTES_COMPLIANCE_GATE=0 to skip.
if [[ "${ATTRIBUTES_COMPLIANCE_GATE:-1}" == "1" ]]; then
  echo "PHPUnit (fast): attributes VM compliance (Attribute*)..."
  ci_run_phpunit --filter Attribute
fi

# HashTable rehash with string keys (#66, #1956). Default on; set REHASH_COMPLIANCE_GATE=0 to skip.
if [[ "${REHASH_COMPLIANCE_GATE:-1}" == "1" ]]; then
  echo "PHPUnit (fast): hashtable rehash VM compliance (array_rehash_string_keys)..."
  ci_run_phpunit --filter array_rehash_string_keys
fi

# Null coalescing ?? / ??= VM compliance (#99, #1960). Default on; set COALESCE_COMPLIANCE_GATE=0 to skip.
if [[ "${COALESCE_COMPLIANCE_GATE:-1}" == "1" ]]; then
  echo "PHPUnit (fast): null coalescing VM compliance (Coalesce*)..."
  ci_run_phpunit --filter Coalesce
fi

# try/catch/throw VM compliance (#2084, #57). Default on; set TRY_CATCH_COMPLIANCE_GATE=0 to skip.
if [[ "${TRY_CATCH_COMPLIANCE_GATE:-1}" == "1" ]]; then
  echo "PHPUnit (fast): try/catch VM compliance (TryCatch*)..."
  ci_run_phpunit --filter TryCatch
fi

# Dynamic $fn() JIT slice when LLVM + MCJIT ready (#2060). Default on; set JIT_VARIABLE_FUNCTION_COMPLIANCE_GATE=0 to skip.
ci_run_jit_variable_function_compliance "$@"

# M4 bootstrap-loop dry-run when opt-in (issue #1929; default off in ci-defaults).
ci_run_bootstrap_loop_probe

# Self-host presenter when opt-in (issue #1928; script pending #1865).
ci_run_north_star2_verify

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
