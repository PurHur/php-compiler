#!/usr/bin/env bash
# Shared CI bootstrap for ci-fast.sh and ci-local.sh (issue #436).
set -euo pipefail

_CI_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
_CI_REPO_ROOT="$(cd "$_CI_SCRIPT_DIR/.." && pwd)"

# shellcheck source=ci-defaults.env
source "$_CI_SCRIPT_DIR/ci-defaults.env"
# shellcheck source=php-env.sh
source "$_CI_SCRIPT_DIR/php-env.sh"
# shellcheck source=ci-resource-limits.sh
source "$_CI_SCRIPT_DIR/ci-resource-limits.sh"

ci_prepare_test_runtime() {
  ci_guard_parallel_ci
  ci_apply_resource_limits
  ci_apply_default_memory_env
  export PHP_COMPILER_VM_RUNNER="${PHP_COMPILER_VM_RUNNER:-${_CI_REPO_ROOT}/script/run-vm-guarded.sh}"
}

ci_repo_root() {
  printf '%s\n' "$_CI_REPO_ROOT"
}

ci_cd_repo() {
  cd "$_CI_REPO_ROOT"
}

ci_install_deps() {
  local ext_dir="$PHP_COMPILER_EXT_DIR"
  if command -v composer >/dev/null 2>&1 && composer --version >/dev/null 2>&1; then
    COMPOSER=(composer)
  elif [[ -f /tmp/composer.phar ]]; then
    COMPOSER=("$PHP_BIN" -d "extension=$ext_dir/phar.so" -d "extension=$ext_dir/mbstring.so" /tmp/composer.phar)
  else
    python3 -c "import urllib.request; urllib.request.urlretrieve('https://getcomposer.org/download/latest-stable/composer.phar','/tmp/composer.phar')"
    COMPOSER=("$PHP_BIN" -d "extension=$ext_dir/phar.so" -d "extension=$ext_dir/mbstring.so" /tmp/composer.phar)
  fi
  "${COMPOSER[@]}" install --no-interaction --ignore-platform-reqs 2>/dev/null || true

  chmod +x script/install-llvm9.sh script/apply-patches.sh 2>/dev/null || true
  if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
    if [[ -x script/install-llvm9.sh ]]; then
      script/install-llvm9.sh || true
    fi
  fi
  if [[ -x script/apply-patches.sh ]]; then
    script/apply-patches.sh || true
  fi
}

# Regenerate committed generator output when stale (issue #765; same ergonomics as capability-matrix write).
ci_ensure_generated_doc() {
  local script="$1"
  local label="$2"
  if "$PHP_BIN" "${PHP_OPTS[@]}" "$script" --check; then
    return 0
  fi
  echo "Regenerating stale ${label} (issue #765)..."
  "$PHP_BIN" "${PHP_OPTS[@]}" "$script"
}

ci_run_capability_syntax_check() {
  if [[ "${CAPABILITY_SYNTAX_CHECK:-1}" != "1" ]]; then
    echo "capability-syntax: skipped (CAPABILITY_SYNTAX_CHECK=${CAPABILITY_SYNTAX_CHECK:-0}, issue #803)"
    return 0
  fi
  echo "capability-syntax: stale check (CAPABILITY_SYNTAX_CHECK=1 default, issue #803)..."
  "$PHP_BIN" "${PHP_OPTS[@]}" script/capability-syntax.php --check
}

ci_run_inventory_checks() {
  script/check-no-unlimited-memory.sh
  script/check-init-miniwebapp-parity.sh
  "$PHP_BIN" "${PHP_OPTS[@]}" script/capability-matrix.php --check
  ci_run_capability_syntax_check
  ci_ensure_generated_doc script/bootstrap-inventory.php docs/bootstrap-inventory.md
  ci_ensure_generated_doc script/bootstrap-profile.php docs/bootstrap-profile.json
}

ci_llvm_dir() {
  LLVM_DIR="${PHP_COMPILER_LLVM_PATH:-$_CI_REPO_ROOT/.llvm}"
  printf '%s\n' "$LLVM_DIR"
}

ci_report_llvm_status() {
  local llvm_dir
  llvm_dir="$(ci_llvm_dir)"
  if [[ -f "$llvm_dir/libLLVM-9.so.1" ]]; then
    echo "LLVM 9 found at $llvm_dir: JIT compliance, AOT fixtures (simple_web_*, static_web), and ExampleWebAotTest will run."
  else
    echo "LLVM 9 missing: @group llvm tests (JIT, AOT, web AOT) are skipped. Run: script/install-llvm9.sh"
  fi
}

# Optional early JIT bootstrap check for ci-fast (issue #728; default off).
ci_jit_preflight_gate() {
  if [[ "${JIT_PREFLIGHT_GATE:-}" != "1" ]]; then
    return 0
  fi
  echo "JIT preflight gate (JIT_PREFLIGHT_GATE=1, issue #728)..."
  "$PHP_BIN" "${PHP_OPTS[@]}" script/check-jit-compliance-ran.php --preflight "$_CI_REPO_ROOT"
}

ci_can_bind_loopback() {
  "$PHP_BIN" "${PHP_OPTS[@]}" script/can-bind-loopback.php
}

ci_configure_serve_tests() {
  if [[ -n "${PHP_COMPILER_SKIP_SERVE_TESTS:-}" ]]; then
    echo "HTTP serve integration tests skipped (PHP_COMPILER_SKIP_SERVE_TESTS is set)."
    return
  fi
  if [[ "${PHP_COMPILER_RUN_SERVE_TESTS:-}" == "1" ]]; then
    echo "HTTP serve integration tests forced (PHP_COMPILER_RUN_SERVE_TESTS=1)."
    return
  fi
  if ci_can_bind_loopback; then
    echo "Loopback TCP bind OK: ServeTest and ServeAotTest will run."
    return
  fi
  export PHP_COMPILER_SKIP_SERVE_TESTS=1
  echo "Cannot bind 127.0.0.1 — skipping @group serve tests."
  echo "  Set PHP_COMPILER_RUN_SERVE_TESTS=1 to force, or PHP_COMPILER_SKIP_SERVE_TESTS=1 to silence."
}

ci_llvm_ready() {
  local llvm_dir
  llvm_dir="$(ci_llvm_dir)"
  [[ -f "$llvm_dir/libLLVM-9.so.1" ]]
}

ci_run_bootstrap_aot_lint() {
  echo "Bootstrap AOT lint (issue #212 Phase B)..."
  set +e
  "$PHP_BIN" "${PHP_OPTS[@]}" script/bootstrap-aot-lint.php
  local bootstrap_lint_code=$?
  set -e
  if [[ "$bootstrap_lint_code" -eq 0 ]]; then
    :
  elif [[ "$bootstrap_lint_code" -eq 2 ]]; then
    echo "bootstrap-aot-lint skipped (LLVM 9 not available)."
  else
    exit 1
  fi
}

ci_should_run_jit() {
  if [[ -n "${PHP_COMPILER_FORCE_JIT_TESTS:-}" ]]; then
    echo "JIT compliance forced (PHP_COMPILER_FORCE_JIT_TESTS=1)."
    return 0
  fi
  if "$PHP_BIN" "${PHP_OPTS[@]}" script/jit-runtime-probe.php; then
    return 0
  fi
  echo "JIT MCJIT probe failed (segfault or bad output); skipping @group jit."
  echo "  Re-run with PHP_COMPILER_FORCE_JIT_TESTS=1 after fixing bin/jit.php / LLVM 9."
  return 1
}

ci_guard_jit_compliance() {
  local junit_path="$1"
  local llvm_dir="$2"
  if [[ -n "${PHP_COMPILER_ALLOW_JIT_SKIP:-}" ]]; then
    echo "JIT compliance guard skipped (PHP_COMPILER_ALLOW_JIT_SKIP is set)."
    return 0
  fi
  "$PHP_BIN" "${PHP_OPTS[@]}" script/check-jit-compliance-ran.php "$junit_path" "$llvm_dir"
}

# Shell curl harness for 003-MiniWebApp PATH_INFO routes (issue #633).
ci_run_miniwebapp_web_smoke() {
  if [[ -n "${PHP_COMPILER_SKIP_SERVE_TESTS:-}" ]]; then
    echo "examples-web-smoke (003): skipped (PHP_COMPILER_SKIP_SERVE_TESTS is set)"
    return 0
  fi
  if ! ci_can_bind_loopback; then
    echo "examples-web-smoke (003): skipped (cannot bind loopback TCP)"
    return 0
  fi
  echo "examples-web-smoke (003): MiniWebApp PATH_INFO curls (MINIWEBAPP_WEB_SMOKE_GATE=1 default, #633, #664)..."
  "$_CI_SCRIPT_DIR/examples-web-smoke.sh" --miniwebapp-only
}

# HTTP curl harness for shipped web examples via phpc serve --aot (issue #444).
ci_run_examples_web_smoke_aot() {
  if [[ -n "${PHP_COMPILER_SKIP_SERVE_TESTS:-}" ]]; then
    echo "examples-web-smoke (AOT): skipped (PHP_COMPILER_SKIP_SERVE_TESTS is set)"
    return 0
  fi
  if ! ci_can_bind_loopback; then
    echo "examples-web-smoke (AOT): skipped (cannot bind loopback TCP)"
    return 0
  fi
  echo "examples-web-smoke-prebuild: building shipped web example AOT binaries..."
  "$_CI_SCRIPT_DIR/examples-web-smoke-prebuild.sh"
  echo "examples-web-smoke (AOT): HTTP harness via phpc serve --aot..."
  "$_CI_SCRIPT_DIR/examples-web-smoke.sh" --aot
}

# CLI AOT build + execute smoke (issue #667); default on via EXAMPLES_AOT_SMOKE_GATE=1 (#674).
ci_run_examples_aot_smoke() {
  if [[ "${EXAMPLES_AOT_SMOKE_GATE:-1}" != "1" ]]; then
    return 0
  fi
  echo "examples-aot-smoke: CLI build + execute (EXAMPLES_AOT_SMOKE_GATE=1 default, #667, #674)..."
  "$_CI_SCRIPT_DIR/examples-aot-smoke.sh"
}

# @group aot-link PHPUnit; 003 execute tests opt-in via MINIWEBAPP_AOT_EXECUTE_GATE (#791).
ci_run_aot_link_phpunit() {
  local -a aot_link_args=(--group aot-link --exclude-group serve)
  if [[ "${MINIWEBAPP_AOT_EXECUTE_GATE:-0}" != "1" ]]; then
    aot_link_args+=(--exclude-group miniwebapp-aot-execute)
  fi
  echo "PHPUnit: AOT link + execute (@group aot-link; MINIWEBAPP_AOT_EXECUTE_GATE=${MINIWEBAPP_AOT_EXECUTE_GATE:-0})..."
  "$PHP_BIN" "${PHP_OPTS[@]}" vendor/bin/phpunit "${aot_link_args[@]}" "$@"
}
