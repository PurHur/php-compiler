#!/usr/bin/env bash
# Local CI baseline: install deps and run the full PHPUnit suite (no Docker).
set -euo pipefail

# shellcheck source=ci-common.sh
source "$(dirname "$0")/ci-common.sh"

ci_cd_repo
ci_prepare_test_runtime
ci_install_deps
ci_run_inventory_checks
ci_report_llvm_status
ci_configure_serve_tests

echo "PHPUnit: VM, compliance (no LLVM), real-world (includes ExamplesCompileTest VM lint/smoke)..."
"$PHP_BIN" "${PHP_OPTS[@]}" vendor/bin/phpunit --exclude-group llvm,serve "$@"

if [[ -n "${MINIWEBAPP_SERVE_GATE:-}" && "${MINIWEBAPP_SERVE_GATE}" == "1" && -n "${PHP_COMPILER_SKIP_SERVE_TESTS:-}" ]]; then
  echo "MINIWEBAPP_SERVE_GATE=1 requires serve tests; unset PHP_COMPILER_SKIP_SERVE_TESTS (#622)" >&2
  exit 1
fi

if [[ -z "${PHP_COMPILER_SKIP_SERVE_TESTS:-}" ]]; then
  serve_groups=(--group serve)
  if [[ "${MINIWEBAPP_SERVE_GATE:-}" == "1" ]]; then
    serve_groups+=(--exclude-group miniwebapp)
  fi
  echo "PHPUnit: HTTP serve (bin/serve.php, phpc serve --aot)..."
  "$PHP_BIN" "${PHP_OPTS[@]}" vendor/bin/phpunit "${serve_groups[@]}" "$@"

  if [[ "${MINIWEBAPP_SERVE_GATE:-}" == "1" ]]; then
    echo "PHPUnit: MiniWebApp ServeTest (MINIWEBAPP_SERVE_GATE=1, #470)..."
    "$PHP_BIN" "${PHP_OPTS[@]}" vendor/bin/phpunit --filter ServeTest --group miniwebapp --fail-on-skipped "$@"
  fi
fi

if ci_llvm_ready; then
  ci_apply_llvm_memory_env
  ci_run_bootstrap_aot_lint

  if ci_should_run_jit; then
    echo "PHPUnit: AOT lint only (@group aot-lint)..."
    "$PHP_BIN" "${PHP_OPTS[@]}" vendor/bin/phpunit --group aot-lint "$@"

    echo "PHPUnit: JIT compliance (@group jit)..."
    LLVM_JUNIT="$(mktemp "${TMPDIR:-/tmp}/llvm-jit-junit.XXXXXX.xml")"
    "$PHP_BIN" "${PHP_OPTS[@]}" vendor/bin/phpunit --group jit --log-junit "$LLVM_JUNIT" "$@"
    ci_guard_jit_compliance "$LLVM_JUNIT" "$(ci_llvm_dir)"
    rm -f "$LLVM_JUNIT"

    echo "PHPUnit: AOT link + execute (@group aot-link, excluding serve)..."
    "$PHP_BIN" "${PHP_OPTS[@]}" vendor/bin/phpunit --group aot-link --exclude-group serve "$@"
  else
    echo "PHPUnit: AOT lint (@group aot-lint)..."
    "$PHP_BIN" "${PHP_OPTS[@]}" vendor/bin/phpunit --group aot-lint "$@"

    echo "PHPUnit: AOT link + execute (@group aot-link — PHPT fixtures, web examples)..."
    "$PHP_BIN" "${PHP_OPTS[@]}" vendor/bin/phpunit --group aot-link --exclude-group serve "$@"
  fi

  ci_run_examples_web_smoke_aot
fi
