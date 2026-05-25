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
  ci_export_llvm_env
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

ci_run_wave3_roadmap_sync_check() {
  if [[ "${WAVE3_ROADMAP_SYNC_GATE:-1}" != "1" ]]; then
    return 0
  fi
  echo "Wave 3 roadmap sync (WAVE3_ROADMAP_SYNC_GATE=1, issue #1802)..."
  "$PHP_BIN" "${PHP_OPTS[@]}" script/check-wave3-roadmap-sync.php
}

ci_run_m2_spine_issue_hygiene_check() {
  if [[ "${M2_SPINE_ISSUE_HYGIENE_GATE:-1}" != "1" ]]; then
    return 0
  fi
  echo "M2 spine issue hygiene (M2_SPINE_ISSUE_HYGIENE_GATE=1, issue #1808)..."
  "$PHP_BIN" "${PHP_OPTS[@]}" script/check-m2-spine-issue-hygiene.php
}

ci_run_examples_readme_sync_check() {
  if [[ "${EXAMPLES_README_SYNC_GATE:-1}" != "1" ]]; then
    return 0
  fi
  echo "Examples README sync (EXAMPLES_README_SYNC_GATE=1, issue #1822)..."
  "$PHP_BIN" "${PHP_OPTS[@]}" script/check-examples-readme-sync.php
}

ci_run_examples_ladder_discovery_check() {
  if [[ "${EXAMPLES_LADDER_DISCOVERY_GATE:-1}" != "1" ]]; then
    return 0
  fi
  echo "Examples ladder discovery (EXAMPLES_LADDER_DISCOVERY_GATE=1, issue #1913)..."
  "$PHP_BIN" "${PHP_OPTS[@]}" script/check-examples-ladder-discovery.php
}

ci_run_rebuild_examples_005_sync_check() {
  if [[ "${REBUILD_EXAMPLES_005_SYNC_GATE:-0}" != "1" ]]; then
    return 0
  fi
  echo "Rebuild examples 005 row sync (REBUILD_EXAMPLES_005_SYNC_GATE=1, issue #1930)..."
  "$PHP_BIN" "${PHP_OPTS[@]}" script/check-rebuild-examples-005-row.php
}

ci_run_capabilities_sessionsweb_sync_check() {
  if [[ "${CAPABILITIES_SESSIONSWEB_SYNC_GATE:-1}" != "1" ]]; then
    return 0
  fi
  echo "Capabilities SessionsWeb sync (CAPABILITIES_SESSIONSWEB_SYNC_GATE=1, issue #1947)..."
  "$PHP_BIN" "${PHP_OPTS[@]}" script/check-capabilities-sessionsweb-sync.php
}

ci_run_root_readme_sync_check() {
  if [[ "${ROOT_README_SYNC_GATE:-0}" != "1" ]]; then
    return 0
  fi
  echo "Root README sync (ROOT_README_SYNC_GATE=1, issue #1832)..."
  "$PHP_BIN" "${PHP_OPTS[@]}" script/check-root-readme-sync.php
}

ci_run_selfhost_spine_count_sync_check() {
  if [[ "${SELFHOST_SPINE_COUNT_SYNC_GATE:-0}" != "1" ]]; then
    return 0
  fi
  echo "Self-host spine count sync (SELFHOST_SPINE_COUNT_SYNC_GATE=1, issue #1834)..."
  "$PHP_BIN" "${PHP_OPTS[@]}" script/check-selfhost-spine-count-sync.php
}

ci_run_selfhost_spine_coverage_sync_check() {
  if [[ "${SELFHOST_SPINE_COVERAGE_SYNC_GATE:-0}" != "1" ]]; then
    return 0
  fi
  echo "Self-host spine coverage sync (SELFHOST_SPINE_COVERAGE_SYNC_GATE=1, issue #1945)..."
  "$PHP_BIN" "${PHP_OPTS[@]}" script/check-selfhost-spine-coverage-sync.php
}

ci_run_m3_allowlist_sync_check() {
  if [[ "${M3_ALLOWLIST_SYNC_GATE:-0}" != "1" ]]; then
    return 0
  fi
  echo "M3 allowlist snapshot sync (M3_ALLOWLIST_SYNC_GATE=1, issue #1905)..."
  "$PHP_BIN" "${PHP_OPTS[@]}" script/check-m3-allowlist-snapshot.php
}

ci_run_bootstrap_m5_doc_sync_check() {
  if [[ "${BOOTSTRAP_M5_DOC_SYNC_GATE:-0}" != "1" ]]; then
    return 0
  fi
  echo "bootstrap-m5-fast-path doc sync (BOOTSTRAP_M5_DOC_SYNC_GATE=1, issue #1984)..."
  "$PHP_BIN" "${PHP_OPTS[@]}" script/check-bootstrap-m5-doc-sync.php
}

ci_run_init_sessionsweb_parity_check() {
  if [[ "${INIT_SESSIONSWEB_PARITY_GATE:-1}" != "1" ]]; then
    return 0
  fi
  echo "init-sessionsweb template parity (INIT_SESSIONSWEB_PARITY_GATE=1, issue #1902)..."
  script/check-init-sessionsweb-parity.sh
}

ci_run_inventory_checks() {
  script/check-no-unlimited-memory.sh
  script/check-stale-issue-refs.sh
  script/check-init-miniwebapp-parity.sh
  ci_run_init_sessionsweb_parity_check
  "$PHP_BIN" "${PHP_OPTS[@]}" script/capability-matrix.php --check
  ci_run_capability_syntax_check
  ci_ensure_generated_doc script/bootstrap-inventory.php docs/bootstrap-inventory.md
  ci_ensure_generated_doc script/bootstrap-profile.php docs/bootstrap-profile.json
  ci_run_wave3_roadmap_sync_check
  ci_run_m2_spine_issue_hygiene_check
  ci_run_examples_readme_sync_check
  ci_run_examples_ladder_discovery_check
  ci_run_rebuild_examples_005_sync_check
  ci_run_capabilities_sessionsweb_sync_check
  ci_run_root_readme_sync_check
  ci_run_selfhost_spine_count_sync_check
  ci_run_selfhost_spine_coverage_sync_check
  ci_run_m3_allowlist_sync_check
  ci_run_bootstrap_m5_doc_sync_check
}

ci_llvm_dir() {
  LLVM_DIR="${PHP_COMPILER_LLVM_PATH:-$_CI_REPO_ROOT/.llvm}"
  if [[ -f "$LLVM_DIR/libLLVM-9.so.1" ]]; then
    LLVM_DIR="$(cd "$LLVM_DIR" && pwd)"
  fi
  printf '%s\n' "$LLVM_DIR"
}

# Absolute LLVM paths for PHPUnit and bin/jit.php / bin/vm.php children (#98).
ci_export_llvm_env() {
  local llvm_dir
  llvm_dir="$(ci_llvm_dir)"
  if [[ ! -f "$llvm_dir/libLLVM-9.so.1" ]]; then
    return 0
  fi
  export PHP_COMPILER_LLVM_PATH="$llvm_dir"
  export LD_LIBRARY_PATH="${llvm_dir}${LD_LIBRARY_PATH:+:${LD_LIBRARY_PATH}}"
  export PATH="${llvm_dir}:${PATH}"
}

ci_run_phpunit() {
  ci_export_llvm_env
  "$PHP_BIN" "${PHP_OPTS[@]}" vendor/bin/phpunit "$@"
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

# compiler_minimal -l/-o probe (issue #816, #829); default on in ci-local llvm tail only.
ci_run_bootstrap_selfhost_probe() {
  if [[ "${BOOTSTRAP_SELFHOST_PROBE_GATE:-0}" != "1" ]]; then
    return 0
  fi
  if ! ci_llvm_ready; then
    echo "bootstrap-selfhost-probe: skipped (LLVM 9 not available)"
    return 0
  fi
  echo "bootstrap-selfhost-probe (BOOTSTRAP_SELFHOST_PROBE_GATE=1, issue #829)..."
  local -a probe_args=()
  if [[ "${BOOTSTRAP_SELFHOST_PROBE_UPDATE:-0}" == "1" ]]; then
    probe_args+=(--update-inventory)
  fi
  "$_CI_SCRIPT_DIR/bootstrap-selfhost-compile-probe.sh" "${probe_args[@]}"
}

# M2 lib spine VM -r smoke (issue #1846); default on in ci-defaults (#1867).
ci_run_bootstrap_lib_spine_vm_smoke() {
  if [[ "${BOOTSTRAP_LIB_SPINE_VM_SMOKE_GATE:-1}" != "1" ]]; then
    return 0
  fi
  if ! ci_llvm_ready; then
    echo "bootstrap-selfhost-lib-spine-vm-smoke: skipped (LLVM 9 not available)"
    return 0
  fi
  echo "bootstrap-selfhost-lib-spine-vm-smoke (BOOTSTRAP_LIB_SPINE_VM_SMOKE_GATE=1, issue #1846)..."
  "$_CI_SCRIPT_DIR/bootstrap-selfhost-lib-spine-vm-smoke.sh"
}

# North Star 2 presenter bundle in fast CI (issue #1928); default off until #1865 script lands.
ci_run_north_star2_verify() {
  if [[ "${NORTH_STAR2_VERIFY_GATE:-0}" != "1" ]]; then
    return 0
  fi
  local ns2_script="$_CI_SCRIPT_DIR/north-star2-verify.sh"
  if [[ ! -x "$ns2_script" ]]; then
    echo "north-star2-verify: skipped (script missing — pending #1865; NORTH_STAR2_VERIFY_GATE=1)"
    return 0
  fi
  echo "north-star2-verify (NORTH_STAR2_VERIFY_GATE=1, issue #1928)..."
  local ns2_args=(--skip-llvm-tail)
  if ci_llvm_ready; then
    ns2_args=()
  fi
  "$ns2_script" "${ns2_args[@]}"
}

# M4 bootstrap-loop dry-run probe (issue #1777, #1498); default off until M3 strict is stable.
ci_run_bootstrap_loop_probe() {
  if [[ "${BOOTSTRAP_LOOP_PROBE_GATE:-0}" != "1" ]]; then
    return 0
  fi
  if ! ci_llvm_ready; then
    echo "bootstrap-loop-probe: skipped (LLVM 9 not available)"
    return 0
  fi
  echo "bootstrap-loop-probe (BOOTSTRAP_LOOP_PROBE_GATE=1, --dry-run, issue #1777, #1498, #1929)..."
  if ! "$_CI_SCRIPT_DIR/bootstrap-loop-probe.sh" --dry-run; then
    echo "bootstrap-loop-probe: failed — see docs/bootstrap-selfhost.md (#1498)" >&2
    return 1
  fi
}

# Wave gate: selfhost-lint → aot-lint → probe (default on when LLVM ready; BOOTSTRAP_WAVE_CHECK=0 to skip).
ci_run_bootstrap_wave_check() {
  if [[ "${BOOTSTRAP_WAVE_CHECK:-1}" != "1" ]]; then
    return 0
  fi
  if ! ci_llvm_ready; then
    echo "bootstrap-wave-check: skipped (LLVM 9 not available)"
    return 0
  fi
  echo "bootstrap-wave-check (BOOTSTRAP_WAVE_CHECK=1)..."
  "$_CI_SCRIPT_DIR/bootstrap-wave-check.sh" --fail-fast
}

# M3 compile-smoke partial probe (issue #1937): bundle link + Zend emit + native run; strict gate separate.
ci_run_bootstrap_compile_smoke_probe() {
  if [[ "${BOOTSTRAP_M3_COMPILE_SMOKE_PROBE_GATE:-1}" != "1" ]]; then
    return 0
  fi
  if ! ci_llvm_ready; then
    echo "bootstrap-compile-smoke-probe: skipped (LLVM 9 not available)"
    return 0
  fi
  echo "bootstrap-compile-smoke-probe (BOOTSTRAP_M3_COMPILE_SMOKE_PROBE_GATE=1, issue #1937)..."
  "$_CI_SCRIPT_DIR/bootstrap-selfhost-compile-smoke-probe.sh"
}

# M3 compile-smoke strict native emit (issue #1937); default off until emit_path=native stable.
ci_run_bootstrap_m3_compile_smoke_strict() {
  if [[ "${BOOTSTRAP_M3_COMPILE_SMOKE_STRICT_GATE:-0}" != "1" ]]; then
    return 0
  fi
  if ! ci_llvm_ready; then
    echo "bootstrap-m3-compile-smoke-strict: skipped (LLVM 9 not available)"
    return 0
  fi
  echo "bootstrap-m3-compile-smoke-strict (BOOTSTRAP_M3_COMPILE_SMOKE_STRICT_GATE=1, issue #1937/#1977)..."
  BOOTSTRAP_M3_LINK_COMPILE_DRIVER=1 \
    BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING=1 \
    BOOTSTRAP_M3_RUNTIME_COMPILE=1 \
    BOOTSTRAP_M3_COMPILE_SMOKE_STRICT=1 \
    "$_CI_SCRIPT_DIR/bootstrap-selfhost-compile-smoke-probe.sh"
}

# M3 HelloWorld strict native emit (issue #1526); default off until emit_path=native stable in Docker.
ci_run_bootstrap_m3_strict() {
  if [[ "${BOOTSTRAP_M3_HELLOWORLD_STRICT_GATE:-0}" != "1" ]]; then
    return 0
  fi
  if ! ci_llvm_ready; then
    echo "bootstrap-m3-strict: skipped (LLVM 9 not available)"
    return 0
  fi
  echo "bootstrap-m3-strict (BOOTSTRAP_M3_HELLOWORLD_STRICT_GATE=1, issue #1526)..."
  BOOTSTRAP_M3_LINK_COMPILE_DRIVER=1 \
    BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING=1 \
    BOOTSTRAP_M3_RUNTIME_COMPILE=1 \
    BOOTSTRAP_M3_HELLOWORLD_STRICT=1 \
    "$_CI_SCRIPT_DIR/bootstrap-selfhost-helloworld-probe.sh"
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

# Shell curl harness for 005-SessionsWeb session flash (issue #1887).
ci_run_sessions_web_smoke() {
  if [[ "${SESSIONS_WEB_SMOKE_GATE:-1}" != "1" ]]; then
    return 0
  fi
  if [[ -n "${PHP_COMPILER_SKIP_SERVE_TESTS:-}" ]]; then
    echo "examples-web-smoke (005): skipped (PHP_COMPILER_SKIP_SERVE_TESTS is set)"
    return 0
  fi
  if ! ci_can_bind_loopback; then
    echo "examples-web-smoke (005): skipped (cannot bind loopback TCP)"
    return 0
  fi
  echo "examples-web-smoke (005): SessionsWeb cookie curls (SESSIONS_WEB_SMOKE_GATE=1 default, #1887)..."
  "$_CI_SCRIPT_DIR/examples-web-smoke.sh" --sessions-only
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

# 003-MiniWebApp AOT HTTP curls via phpc serve --aot (issues #833, #1523); default MINIWEBAPP_WEB_SMOKE_AOT_GATE=1.
ci_run_miniwebapp_web_smoke_aot() {
  if [[ "${MINIWEBAPP_WEB_SMOKE_AOT_GATE:-1}" != "1" ]]; then
    return 0
  fi
  if [[ -n "${PHP_COMPILER_SKIP_SERVE_TESTS:-}" ]]; then
    echo "examples-web-smoke (003 AOT): skipped (PHP_COMPILER_SKIP_SERVE_TESTS is set)"
    return 0
  fi
  if ! ci_can_bind_loopback; then
    echo "examples-web-smoke (003 AOT): skipped (cannot bind loopback TCP)"
    return 0
  fi
  if ! ci_llvm_ready; then
    echo "examples-web-smoke (003 AOT): skipped (LLVM 9 not available)"
    return 0
  fi
  echo "examples-web-smoke (003 AOT): MiniWebApp phpc serve --aot curls (MINIWEBAPP_WEB_SMOKE_AOT_GATE=1 default, #1523, #833)..."
  "$_CI_SCRIPT_DIR/examples-web-smoke.sh" --miniwebapp-only --aot
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

# phpc deploy + PHPC_DEPLOY_ROOT CGI smoke for 001/002 (issue #718); default on via DEPLOY_SMOKE_GATE=1 (#737).
ci_run_deploy_smoke() {
  if [[ "${DEPLOY_SMOKE_GATE:-1}" != "1" ]]; then
    return 0
  fi
  if ! ci_llvm_ready; then
    echo "deploy-smoke: skipped (LLVM 9 not available)"
    return 0
  fi
  echo "deploy-smoke: phpc deploy + PHPC_DEPLOY_ROOT (DEPLOY_SMOKE_GATE=1 default, #718, #737; 003 execute DEPLOY_SMOKE_003_EXECUTE=1 default, #1530)..."
  "$_CI_SCRIPT_DIR/deploy-smoke.sh" --example 001
  "$_CI_SCRIPT_DIR/deploy-smoke.sh" --example 002
  if [[ "${DEPLOY_SMOKE_003_EXECUTE:-1}" == "1" || "${MINIWEBAPP_AOT_EXECUTE_GATE:-0}" == "1" ]]; then
    "$_CI_SCRIPT_DIR/deploy-smoke.sh" --example 003
  fi
  if [[ "${SESSIONS_WEB_DEPLOY_SMOKE_GATE:-0}" == "1" ]]; then
    "$_CI_SCRIPT_DIR/deploy-smoke.sh" --example 005
  fi
}

# @group aot-link PHPUnit (link-only; execute is ci_run_miniwebapp_aot_execute — #775).
# 005 link: ExamplesCompileTest::test005SessionsWebAotLink when SESSIONS_WEB_AOT_LINK_GATE=1 (#1946).
ci_run_aot_link_phpunit() {
  local -a aot_link_args=(--group aot-link --exclude-group serve --exclude-group miniwebapp-aot-execute --exclude-group miniwebapp-aot-serve --exclude-group sessionsweb-aot-execute)
  echo "PHPUnit: AOT link (@group aot-link; SESSIONS_WEB_AOT_LINK_GATE=${SESSIONS_WEB_AOT_LINK_GATE:-0})..."
  ci_run_phpunit "${aot_link_args[@]}" "$@"
}

# 005-SessionsWeb AOT binary CLI execute (issue #1891); opt-in SESSIONS_WEB_AOT_SMOKE_GATE=1.
ci_run_sessions_web_aot_execute() {
  if [[ "${SESSIONS_WEB_AOT_SMOKE_GATE:-0}" != "1" ]]; then
    return 0
  fi
  if ! ci_llvm_ready; then
    echo "PHPUnit: SessionsWeb AOT execute skipped (LLVM 9 not available)"
    return 0
  fi
  echo "PHPUnit: SessionsWeb AOT execute (@group sessionsweb-aot-execute; SESSIONS_WEB_AOT_SMOKE_GATE=1, #1891)..."
  ci_run_phpunit --group sessionsweb-aot-execute "$@"
}

# 003-MiniWebApp AOT binary CLI execute (issues #747, #775); default on via MINIWEBAPP_AOT_EXECUTE_GATE=1.
ci_run_miniwebapp_aot_execute() {
  if [[ "${MINIWEBAPP_AOT_EXECUTE_GATE:-1}" != "1" ]]; then
    return 0
  fi
  if ! ci_llvm_ready; then
    echo "PHPUnit: MiniWebApp AOT execute skipped (LLVM 9 not available)"
    return 0
  fi
  echo "PHPUnit: MiniWebApp AOT execute (@group miniwebapp-aot-execute; MINIWEBAPP_AOT_EXECUTE_GATE=1, #747, #775)..."
  ci_run_phpunit --group miniwebapp-aot-execute "$@"
}

# 003-MiniWebApp phpc serve --aot HTTP integration (issues #478, #610, #1524); default MINIWEBAPP_SERVE_AOT_GATE=1.
ci_run_miniwebapp_serve_aot() {
  if [[ "${MINIWEBAPP_SERVE_AOT_GATE:-1}" != "1" && "${MINIWEBAPP_AOT_EXECUTE_GATE:-1}" != "1" ]]; then
    return 0
  fi
  if [[ -n "${PHP_COMPILER_SKIP_SERVE_TESTS:-}" ]]; then
    echo "PHPUnit: MiniWebApp serve-aot skipped (PHP_COMPILER_SKIP_SERVE_TESTS is set)"
    return 0
  fi
  if ! ci_can_bind_loopback; then
    echo "PHPUnit: MiniWebApp serve-aot skipped (cannot bind loopback TCP)"
    return 0
  fi
  if ! ci_llvm_ready; then
    echo "PHPUnit: MiniWebApp serve-aot skipped (LLVM 9 not available)"
    return 0
  fi
  echo "PHPUnit: MiniWebApp serve-aot (@group miniwebapp-aot-serve; MINIWEBAPP_SERVE_AOT_GATE or MINIWEBAPP_AOT_EXECUTE_GATE, #478, #610)..."
  ci_run_phpunit --group miniwebapp-aot-serve "$@"
}

# 003-MiniWebApp bin/jit.php project entry (issues #587, #475); opt-in MINIWEBAPP_JIT_PROJECT_GATE=1.
ci_run_miniwebapp_jit_project() {
  if [[ "${MINIWEBAPP_JIT_PROJECT_GATE:-0}" != "1" ]]; then
    return 0
  fi
  if ! ci_llvm_ready; then
    echo "PHPUnit: MiniWebApp JIT project skipped (LLVM 9 not available)"
    return 0
  fi
  if ! ci_should_run_jit; then
    echo "PHPUnit: MiniWebApp JIT project skipped (JIT MCJIT probe failed)"
    return 0
  fi
  echo "PHPUnit: MiniWebApp JIT project (@group miniwebapp-jit-project; MINIWEBAPP_JIT_PROJECT_GATE=1, #587)..."
  ci_run_phpunit --group miniwebapp-jit-project "$@"
}
