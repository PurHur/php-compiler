#!/usr/bin/env bash
# Fast local CI: VM/compliance + optional serve — no LLVM compile phases (issue #436).
#
# Use while iterating on compiler/VM changes. Before merge, run ./script/ci-local.sh
# (or: phpc test, make test) for the full JIT/AOT/link gate.
set -euo pipefail

# shellcheck source=ci-common.sh
source "$(dirname "$0")/ci-common.sh"

ci_cd_repo
ci_install_deps
ci_run_inventory_checks
ci_report_llvm_status
ci_configure_serve_tests

echo "PHPUnit (fast): VM, compliance, real-world — excluding @group llvm..."
"$PHP_BIN" "${PHP_OPTS[@]}" vendor/bin/phpunit --exclude-group llvm,serve "$@"

if [[ -z "${PHP_COMPILER_SKIP_SERVE_TESTS:-}" ]]; then
  echo "PHPUnit (fast): HTTP serve (bin/serve.php, no AOT compile)..."
  "$PHP_BIN" "${PHP_OPTS[@]}" vendor/bin/phpunit --group serve --exclude-group llvm "$@"
fi

echo "Fast CI finished. Full LLVM compile gate: ./script/ci-local.sh (issue #436)."
