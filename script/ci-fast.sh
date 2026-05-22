#!/usr/bin/env bash
# Fast local CI: VM/compliance + optional serve — no LLVM compile phases (issue #436).
#
# Use while iterating on compiler/VM changes. Before merge, run ./script/ci-local.sh
# (or: phpc test, make test) for the full JIT/AOT/link gate.
set -euo pipefail

# shellcheck source=ci-common.sh
source "$(dirname "$0")/ci-common.sh"

ci_cd_repo
ci_prepare_test_runtime
ci_install_deps
ci_run_inventory_checks
ci_report_llvm_status
ci_configure_serve_tests

echo "PHPUnit (fast): VM, compliance, real-world — excluding @group llvm..."
"$PHP_BIN" "${PHP_OPTS[@]}" vendor/bin/phpunit --exclude-group llvm,serve "$@"

if [[ -n "${MINIWEBAPP_SERVE_GATE:-}" && "${MINIWEBAPP_SERVE_GATE}" == "1" && -n "${PHP_COMPILER_SKIP_SERVE_TESTS:-}" ]]; then
  echo "MINIWEBAPP_SERVE_GATE=1 requires serve tests; unset PHP_COMPILER_SKIP_SERVE_TESTS (#622)" >&2
  exit 1
fi

if [[ -z "${PHP_COMPILER_SKIP_SERVE_TESTS:-}" ]]; then
  serve_groups=(--group serve --exclude-group llvm)
  if [[ "${MINIWEBAPP_SERVE_GATE:-}" == "1" ]]; then
    serve_groups+=(--exclude-group miniwebapp)
  fi
  echo "PHPUnit (fast): HTTP serve (bin/serve.php, no AOT compile)..."
  "$PHP_BIN" "${PHP_OPTS[@]}" vendor/bin/phpunit "${serve_groups[@]}" "$@"

  if [[ "${MINIWEBAPP_SERVE_GATE:-}" == "1" ]]; then
    echo "PHPUnit (fast): MiniWebApp ServeTest (MINIWEBAPP_SERVE_GATE=1, #470)..."
    "$PHP_BIN" "${PHP_OPTS[@]}" vendor/bin/phpunit --filter ServeTest --group miniwebapp --fail-on-skipped "$@"
  fi
fi

# Always lint 003-MiniWebApp even when callers pass --filter (issue #570, #539).
echo "PHPUnit (fast): MiniWebApp project lint (PhpcLintProjectTest)..."
"$PHP_BIN" "${PHP_OPTS[@]}" vendor/bin/phpunit --filter PhpcLintProjectTest

# VM CLI route matrix without TCP (issues #586, #595, #597). Default on while green.
if [[ "${MINIWEBAPP_VM_CLI_GATE:-1}" == "1" ]]; then
  echo "PHPUnit (fast): MiniWebApp VM CLI gates (MiniWebApp*VmCli)..."
  "$PHP_BIN" "${PHP_OPTS[@]}" vendor/bin/phpunit --filter 'MiniWebApp.*VmCli'
fi

echo "Fast CI finished. Full LLVM compile gate: ./script/ci-local.sh (issue #436)."
