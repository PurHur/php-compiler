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
  if [[ "${REBUILD_EXAMPLES_005_SYNC_GATE:-1}" != "1" ]]; then
    return 0
  fi
  echo "Rebuild examples 005 row sync (REBUILD_EXAMPLES_005_SYNC_GATE=1, issue #1930)..."
  "$PHP_BIN" "${PHP_OPTS[@]}" script/check-rebuild-examples-005-row.php
}

ci_run_rebuild_examples_006_sync_check() {
  if [[ "${REBUILD_EXAMPLES_006_SYNC_GATE:-1}" != "1" ]]; then
    return 0
  fi
  echo "Rebuild examples 006 row sync (REBUILD_EXAMPLES_006_SYNC_GATE=1, issue #2018)..."
  "$PHP_BIN" "${PHP_OPTS[@]}" script/check-rebuild-examples-006-row.php
}

ci_run_rebuild_examples_009_sync_check() {
  if [[ "${REBUILD_EXAMPLES_009_SYNC_GATE:-1}" != "1" ]]; then
    return 0
  fi
  echo "Rebuild examples 009 sync (REBUILD_EXAMPLES_009_SYNC_GATE=1, issue #2370)..."
  "$PHP_BIN" "${PHP_OPTS[@]}" script/check-rebuild-examples-009-sync.php
}

ci_run_rebuild_examples_003_jit_project_sync_check() {
  if [[ "${REBUILD_EXAMPLES_003_JIT_PROJECT_SYNC_GATE:-0}" != "1" ]]; then
    return 0
  fi
  echo "Rebuild examples 003 project-JIT sync (REBUILD_EXAMPLES_003_JIT_PROJECT_SYNC_GATE=1, issue #2334)..."
  "$PHP_BIN" "${PHP_OPTS[@]}" script/check-rebuild-examples-003-jit-project-sync.php
}

ci_run_capabilities_sessionsweb_sync_check() {
  if [[ "${CAPABILITIES_SESSIONSWEB_SYNC_GATE:-1}" != "1" ]]; then
    return 0
  fi
  echo "Capabilities SessionsWeb sync (CAPABILITIES_SESSIONSWEB_SYNC_GATE=1, issue #1947)..."
  "$PHP_BIN" "${PHP_OPTS[@]}" script/check-capabilities-sessionsweb-sync.php
}

ci_run_capabilities_fileuploadweb_sync_check() {
  if [[ "${CAPABILITIES_006_SYNC_GATE:-1}" != "1" ]]; then
    return 0
  fi
  echo "Capabilities 006-FileUploadWeb sync (CAPABILITIES_006_SYNC_GATE=1, issue #2019)..."
  "$PHP_BIN" "${PHP_OPTS[@]}" script/check-capabilities-fileuploadweb-sync.php
}

ci_run_capabilities_throws_sync_check() {
  if [[ "${CAPABILITIES_THROWS_SYNC_GATE:-1}" != "1" ]]; then
    return 0
  fi
  echo "Capabilities ThrowsWeb sync (CAPABILITIES_THROWS_SYNC_GATE=1, issue #2144)..."
  "$PHP_BIN" "${PHP_OPTS[@]}" script/check-capabilities-throws-sync.php
}

ci_run_capabilities_oop_sync_check() {
  if [[ "${CAPABILITIES_OOP_SYNC_GATE:-0}" != "1" ]]; then
    return 0
  fi
  echo "Capabilities MiniWebApp OOP sync (CAPABILITIES_OOP_SYNC_GATE=1, issue #2190)..."
  "$PHP_BIN" "${PHP_OPTS[@]}" script/check-capabilities-oop-sync.php
}

ci_run_root_readme_sync_check() {
  if [[ "${ROOT_README_SYNC_GATE:-0}" != "1" ]]; then
    return 0
  fi
  echo "Root README sync (ROOT_README_SYNC_GATE=1, issue #1832)..."
  ROOT_README_006_SYNC_GATE="${ROOT_README_006_SYNC_GATE:-1}" \
    ROOT_README_007_SYNC_GATE="${ROOT_README_007_SYNC_GATE:-1}" \
    "$PHP_BIN" "${PHP_OPTS[@]}" script/check-root-readme-sync.php
}

# development-status.md drift guard (issues #2067, #2083); default on — opt-out with DEVELOPMENT_STATUS_SYNC_GATE=0.
ci_run_development_status_sync_check() {
  if [[ "${DEVELOPMENT_STATUS_SYNC_GATE:-1}" != "1" ]]; then
    echo "development-status sync: skipped (DEVELOPMENT_STATUS_SYNC_GATE=0 opt-out)"
    return 0
  fi
  echo "Development status sync (DEVELOPMENT_STATUS_SYNC_GATE=1, issue #2067)..."
  DEVELOPMENT_STATUS_007_SYNC_GATE="${DEVELOPMENT_STATUS_007_SYNC_GATE:-1}" \
    "$PHP_BIN" "${PHP_OPTS[@]}" script/check-development-status-sync.php
}

ci_run_development_status_007_sync_check() {
  if [[ "${DEVELOPMENT_STATUS_007_SYNC_GATE:-1}" != "1" ]]; then
    return 0
  fi
  echo "Development status 007 sync (DEVELOPMENT_STATUS_007_SYNC_GATE=1, issue #2145)..."
  DEVELOPMENT_STATUS_007_SYNC_GATE=1 "$PHP_BIN" "${PHP_OPTS[@]}" script/check-development-status-sync.php
}

ci_run_root_readme_006_sync_check() {
  if [[ "${ROOT_README_006_SYNC_GATE:-1}" != "1" ]]; then
    return 0
  fi
  echo "Root README 006 sync (ROOT_README_006_SYNC_GATE=1, issue #2017)..."
  ROOT_README_006_SYNC_GATE=1 "$PHP_BIN" "${PHP_OPTS[@]}" script/check-root-readme-sync.php
}

ci_run_root_readme_007_sync_check() {
  if [[ "${ROOT_README_007_SYNC_GATE:-1}" != "1" ]]; then
    return 0
  fi
  echo "Root README 007 sync (ROOT_README_007_SYNC_GATE=1, issue #2094)..."
  ROOT_README_007_SYNC_GATE=1 "$PHP_BIN" "${PHP_OPTS[@]}" script/check-root-readme-sync.php
}

ci_run_root_readme_008_sync_check() {
  if [[ "${ROOT_README_008_SYNC_GATE:-0}" != "1" ]]; then
    return 0
  fi
  echo "Root README 008 sync (ROOT_README_008_SYNC_GATE=1, issue #2229)..."
  ROOT_README_008_SYNC_GATE=1 "$PHP_BIN" "${PHP_OPTS[@]}" script/check-root-readme-sync.php
}

ci_run_root_readme_009_sync_check() {
  if [[ "${ROOT_README_009_SYNC_GATE:-1}" != "1" ]]; then
    return 0
  fi
  echo "Root README 009 sync (ROOT_README_009_SYNC_GATE=1, issue #2353)..."
  "$PHP_BIN" "${PHP_OPTS[@]}" script/check-root-readme-009-sync.php
}

ci_run_development_status_009_sync_check() {
  if [[ "${DEVELOPMENT_STATUS_009_SYNC_GATE:-1}" != "1" ]]; then
    return 0
  fi
  echo "Development status 009 sync (DEVELOPMENT_STATUS_009_SYNC_GATE=1, issue #2353)..."
  "$PHP_BIN" "${PHP_OPTS[@]}" script/check-development-status-009-sync.php
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

ci_run_selfhost_spine_deferred_sync_check() {
  if [[ "${SELFHOST_SPINE_DEFERRED_SYNC_GATE:-0}" != "1" ]]; then
    return 0
  fi
  echo "Self-host spine deferred sync (SELFHOST_SPINE_DEFERRED_SYNC_GATE=1, issue #2202)..."
  "$PHP_BIN" "${PHP_OPTS[@]}" script/check-selfhost-spine-deferred-sync.php
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
  echo "Bootstrap M5 doc sync (BOOTSTRAP_M5_DOC_SYNC_GATE=1, issue #1984)..."
  "$PHP_BIN" "${PHP_OPTS[@]}" script/check-bootstrap-m5-doc-sync.php
}

ci_run_bootstrap_inventory_lint_sync_check() {
  if [[ "${BOOTSTRAP_INVENTORY_LINT_SYNC_GATE:-0}" != "1" ]]; then
    return 0
  fi
  echo "Bootstrap inventory lint snapshot sync (BOOTSTRAP_INVENTORY_LINT_SYNC_GATE=1, issue #2210)..."
  "$PHP_BIN" "${PHP_OPTS[@]}" script/check-bootstrap-inventory-lint-sync.php
}

ci_run_bootstrap_inventory_triage_sync_check() {
  if [[ "${BOOTSTRAP_INVENTORY_TRIAGE_SYNC_GATE:-0}" != "1" ]]; then
    return 0
  fi
  echo "Bootstrap inventory triage snapshot sync (BOOTSTRAP_INVENTORY_TRIAGE_SYNC_GATE=1, issue #2265)..."
  "$PHP_BIN" "${PHP_OPTS[@]}" script/check-bootstrap-inventory-triage-sync.php
}

ci_run_doctor_gates_matrix_sync_check() {
  if [[ "${DOCTOR_GATES_MATRIX_SYNC_GATE:-1}" != "1" ]]; then
    return 0
  fi
  echo "Doctor gates matrix sync (DOCTOR_GATES_MATRIX_SYNC_GATE=1, issue #2380)..."
  "$PHP_BIN" "${PHP_OPTS[@]}" script/check-doctor-gates-sync.php
}

ci_run_selfhost_m4_gen2_sync_check() {
  if [[ "${SELFHOST_M4_GEN2_SYNC_GATE:-1}" != "1" ]]; then
    echo "Self-host M4 gen-2 doc sync: skipped (SELFHOST_M4_GEN2_SYNC_GATE=0 opt-out)"
    return 0
  fi
  echo "Self-host M4 gen-2 doc sync (SELFHOST_M4_GEN2_SYNC_GATE=1, issues #2115, #2175)..."
  "$PHP_BIN" "${PHP_OPTS[@]}" script/check-selfhost-m4-gen2-sync.php
}

ci_run_bootstrap_m3_strict_sync_check() {
  if [[ "${BOOTSTRAP_M3_STRICT_SYNC_GATE:-0}" != "1" ]]; then
    return 0
  fi
  echo "Bootstrap M3 compile-smoke strict doc sync (BOOTSTRAP_M3_STRICT_SYNC_GATE=1, issue #2176)..."
  "$PHP_BIN" "${PHP_OPTS[@]}" script/check-bootstrap-m3-strict-sync.php
}

ci_run_bootstrap_vendor_inventory_sync_check() {
  if [[ "${BOOTSTRAP_VENDOR_INVENTORY_SYNC_GATE:-0}" != "1" ]]; then
    return 0
  fi
  echo "Bootstrap vendor inventory sync (BOOTSTRAP_VENDOR_INVENTORY_SYNC_GATE=1, issue #2030)..."
  ci_ensure_generated_doc script/bootstrap-vendor-inventory.php docs/bootstrap-vendor-inventory.md
}

ci_run_init_miniwebapp_parity_check() {
  if [[ "${INIT_MINIWEBAPP_PARITY_GATE:-1}" != "1" ]]; then
    return 0
  fi
  echo "init-miniwebapp template parity (INIT_MINIWEBAPP_PARITY_GATE=1, issue #2057)..."
  script/check-init-miniwebapp-parity.sh
}

ci_run_miniwebapp_lint_zero_check() {
  if [[ "${MINIWEBAPP_LINT_ZERO_GATE:-1}" != "1" ]]; then
    return 0
  fi
  echo "MiniWebApp lint zero unsupported (MINIWEBAPP_LINT_ZERO_GATE=1, issue #2078)..."
  "$PHP_BIN" "${PHP_OPTS[@]}" script/check-miniwebapp-lint-zero.php
}

ci_run_init_sessionsweb_parity_check() {
  if [[ "${INIT_SESSIONSWEB_PARITY_GATE:-1}" != "1" ]]; then
    return 0
  fi
  echo "init-sessionsweb template parity (INIT_SESSIONSWEB_PARITY_GATE=1, issue #1902)..."
  script/check-init-sessionsweb-parity.sh
}

ci_run_init_fileupload_parity_check() {
  if [[ "${INIT_FILEUPLOAD_PARITY_GATE:-1}" != "1" ]]; then
    return 0
  fi
  echo "init-fileupload template parity (INIT_FILEUPLOAD_PARITY_GATE=1, issue #2004)..."
  script/check-init-fileupload-parity.sh
}

ci_run_init_throwsweb_parity_check() {
  if [[ "${INIT_THROWSWEB_PARITY_GATE:-1}" != "1" ]]; then
    return 0
  fi
  echo "init-throwsweb template parity (INIT_THROWSWEB_PARITY_GATE=1, issue #2127)..."
  script/check-init-throwsweb-parity.sh
}

ci_run_init_selfhostprobe_parity_check() {
  if [[ "${INIT_SELFHOSTPROBE_PARITY_GATE:-1}" != "1" ]]; then
    return 0
  fi
  echo "init-selfhostprobe template parity (INIT_SELFHOSTPROBE_PARITY_GATE=1, issue #2220)..."
  script/check-init-selfhostprobe-parity.sh
}

ci_run_init_fastcgiweb_parity_check() {
  if [[ "${INIT_FASTCGIWEB_PARITY_GATE:-1}" != "1" ]]; then
    return 0
  fi
  echo "init-fastcgiweb template parity (INIT_FASTCGIWEB_PARITY_GATE=1, issue #2342)..."
  script/check-init-fastcgiweb-parity.sh
}

ci_run_init_apijson_parity_check() {
  if [[ "${APIJSON_INIT_PARITY_GATE:-1}" != "1" ]]; then
    return 0
  fi
  echo "init-apijson template parity (APIJSON_INIT_PARITY_GATE=1, issue #2029)..."
  script/check-init-apijson-parity.sh
}

ci_run_inventory_checks() {
  script/check-no-unlimited-memory.sh
  script/check-stale-issue-refs.sh
  ci_run_init_miniwebapp_parity_check
  ci_run_miniwebapp_lint_zero_check
  ci_run_init_sessionsweb_parity_check
  ci_run_init_fileupload_parity_check
  ci_run_init_throwsweb_parity_check
  ci_run_init_selfhostprobe_parity_check
  ci_run_init_fastcgiweb_parity_check
  ci_run_init_apijson_parity_check
  "$PHP_BIN" "${PHP_OPTS[@]}" script/capability-matrix.php --check
  ci_run_capability_syntax_check
  ci_ensure_generated_doc script/bootstrap-inventory.php docs/bootstrap-inventory.md
  ci_ensure_generated_doc script/bootstrap-profile.php docs/bootstrap-profile.json
  ci_run_wave3_roadmap_sync_check
  ci_run_m2_spine_issue_hygiene_check
  ci_run_examples_readme_sync_check
  ci_run_examples_ladder_discovery_check
  ci_run_rebuild_examples_005_sync_check
  ci_run_rebuild_examples_006_sync_check
  ci_run_rebuild_examples_009_sync_check
  ci_run_rebuild_examples_003_jit_project_sync_check
  ci_run_capabilities_sessionsweb_sync_check
  ci_run_capabilities_fileuploadweb_sync_check
  ci_run_capabilities_throws_sync_check
  ci_run_capabilities_oop_sync_check
  ci_run_root_readme_sync_check
  ci_run_root_readme_006_sync_check
  ci_run_root_readme_007_sync_check
  ci_run_root_readme_008_sync_check
  ci_run_root_readme_009_sync_check
  ci_run_development_status_sync_check
  ci_run_development_status_007_sync_check
  ci_run_development_status_009_sync_check
  ci_run_selfhost_spine_count_sync_check
  ci_run_selfhost_spine_coverage_sync_check
  ci_run_selfhost_spine_deferred_sync_check
  ci_run_m3_allowlist_sync_check
  ci_run_bootstrap_m5_doc_sync_check
  ci_run_selfhost_m4_gen2_sync_check
  ci_run_bootstrap_m3_strict_sync_check
  ci_run_bootstrap_vendor_inventory_sync_check
  ci_run_bootstrap_inventory_lint_sync_check
  ci_run_bootstrap_inventory_triage_sync_check
  ci_run_doctor_gates_matrix_sync_check
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

# M3 compiler driver smoke: Compiler.php bundle native link + run (issue #2136); default on (#2137, #2168).
ci_run_bootstrap_compiler_driver_smoke() {
  if [[ "${COMPILER_DRIVER_SMOKE_GATE:-1}" != "1" ]]; then
    echo "bootstrap-compiler-driver-smoke: skipped (COMPILER_DRIVER_SMOKE_GATE=0 opt-out)"
    return 0
  fi
  if ! ci_llvm_ready; then
    echo "bootstrap-compiler-driver-smoke: skipped (LLVM 9 not available)"
    return 0
  fi
  echo "bootstrap-compiler-driver-smoke (COMPILER_DRIVER_SMOKE_GATE=1, issue #2136)..."
  "$_CI_SCRIPT_DIR/bootstrap-selfhost-compiler-driver-smoke-link.sh"
}

# M3 JIT unit probe: lib/JIT.php bundle native link + run (issue #2332); opt-in (#2361).
ci_run_bootstrap_jit_unit_probe() {
  if [[ "${BOOTSTRAP_JIT_UNIT_PROBE_GATE:-0}" != "1" ]]; then
    return 0
  fi
  if ! ci_llvm_ready; then
    echo "bootstrap-selfhost-jit-unit-probe: skipped (LLVM 9 not available)"
    return 0
  fi
  echo "bootstrap-selfhost-jit-unit-probe (BOOTSTRAP_JIT_UNIT_PROBE_GATE=1, issue #2332, #2361)..."
  "$_CI_SCRIPT_DIR/bootstrap-selfhost-jit-unit-probe.sh"
}

# M3 VM unit probe: lib/VM.php bundle native link (+ optional run) (issue #2354); opt-in (#2368).
ci_run_bootstrap_vm_unit_probe() {
  if [[ "${BOOTSTRAP_VM_UNIT_PROBE_GATE:-0}" != "1" ]]; then
    return 0
  fi
  if ! ci_llvm_ready; then
    echo "bootstrap-selfhost-vm-unit-probe: skipped (LLVM 9 not available)"
    return 0
  fi
  echo "bootstrap-selfhost-vm-unit-probe (BOOTSTRAP_VM_UNIT_PROBE_GATE=1, issue #2354)..."
  "$_CI_SCRIPT_DIR/bootstrap-selfhost-vm-unit-probe.sh"
}

# M3 PHPTypes unit probe: lib/JIT.php Type constants bundle native link + run (issue #2430); opt-in (#2433).
ci_run_bootstrap_phptypes_unit_probe() {
  if [[ "${BOOTSTRAP_PHPTYPES_UNIT_PROBE_GATE:-0}" != "1" ]]; then
    return 0
  fi
  if ! ci_llvm_ready; then
    echo "bootstrap-selfhost-types-unit-probe: skipped (LLVM 9 not available)"
    return 0
  fi
  echo "bootstrap-selfhost-types-unit-probe (BOOTSTRAP_PHPTYPES_UNIT_PROBE_GATE=1, issue #2430, #2433)..."
  "$_CI_SCRIPT_DIR/bootstrap-selfhost-types-unit-probe.sh"
}

# M3 emit-TU native execute PHPUnit guard (issue #2444); default off until #2442 green.
ci_run_bootstrap_m3_emit_tu_execute() {
  if [[ "${BOOTSTRAP_M3_EMIT_TU_EXECUTE_GATE:-0}" != "1" ]]; then
    return 0
  fi
  if ! ci_llvm_ready; then
    echo "bootstrap-m3-emit-tu-execute: skipped (LLVM 9 not available)"
    return 0
  fi
  echo "bootstrap-m3-emit-tu-execute (BOOTSTRAP_M3_EMIT_TU_EXECUTE_GATE=1, issue #2444)..."
  ci_run_phpunit --group selfhost-m3-emit
}

# Bootstrap test subset in fast CI (issue #2069); default off — opt-in BOOTSTRAP_TEST_SUBSET_GATE=1.
ci_run_bootstrap_test_subset() {
  if [[ "${BOOTSTRAP_TEST_SUBSET_GATE:-0}" != "1" ]]; then
    return 0
  fi
  echo "bootstrap-test-subset (BOOTSTRAP_TEST_SUBSET_GATE=1, issue #2069)..."
  local -a subset_args=()
  if [[ "${BOOTSTRAP_TEST_SUBSET_STRICT:-0}" == "1" ]]; then
    subset_args+=(--strict)
  fi
  "$_CI_SCRIPT_DIR/bootstrap-test-subset.sh" "${subset_args[@]}"
}

# Self-host presenter bundle in fast CI (issue #1928, #2051); default on — opt-out with NORTH_STAR2_VERIFY_GATE=0.
ci_run_north_star2_verify() {
  if [[ "${NORTH_STAR2_VERIFY_GATE:-1}" != "1" ]]; then
    echo "north-star2-verify: skipped (NORTH_STAR2_VERIFY_GATE=0 opt-out)"
    return 0
  fi
  local ns2_script="$_CI_SCRIPT_DIR/north-star2-verify.sh"
  if [[ ! -x "$ns2_script" ]]; then
    echo "north-star2-verify: skipped (script missing — run from repo root; NORTH_STAR2_VERIFY_GATE=0 to opt out)"
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

# M3 runtime compile-smoke partial probe (issue #2294): bundle link + Zend emit + native run; strict gate separate.
ci_run_bootstrap_runtime_compile_smoke_probe() {
  if [[ "${BOOTSTRAP_RUNTIME_COMPILE_SMOKE_PROBE_GATE:-1}" != "1" ]]; then
    return 0
  fi
  if ! ci_llvm_ready; then
    echo "bootstrap-runtime-compile-smoke-probe: skipped (LLVM 9 not available)"
    return 0
  fi
  echo "bootstrap-runtime-compile-smoke-probe (BOOTSTRAP_RUNTIME_COMPILE_SMOKE_PROBE_GATE=1, issue #2294)..."
  "$_CI_SCRIPT_DIR/bootstrap-selfhost-runtime-compile-smoke.sh"
}

# M3 runtime compile-smoke strict native emit (issue #2294); default off until emit_path=native stable.
ci_run_bootstrap_runtime_compile_smoke_strict() {
  if [[ "${BOOTSTRAP_RUNTIME_COMPILE_SMOKE_STRICT_GATE:-0}" != "1" ]]; then
    return 0
  fi
  if ! ci_llvm_ready; then
    echo "bootstrap-runtime-compile-smoke-strict: skipped (LLVM 9 not available)"
    return 0
  fi
  echo "bootstrap-runtime-compile-smoke-strict (BOOTSTRAP_RUNTIME_COMPILE_SMOKE_STRICT_GATE=1, issue #2294)..."
  BOOTSTRAP_M3_LINK_COMPILE_DRIVER=1 \
    BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING=1 \
    BOOTSTRAP_M3_RUNTIME_COMPILE=1 \
    BOOTSTRAP_M3_RUNTIME_COMPILE_SMOKE_STRICT=1 \
    "$_CI_SCRIPT_DIR/bootstrap-selfhost-runtime-compile-smoke.sh"
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

# M4 bootstrap-loop dry-run probe in ci-local LLVM tail (issue #2058); after M3 strict gates.
ci_run_bootstrap_m4_loop_probe() {
  if [[ "${BOOTSTRAP_M4_LOOP_PROBE:-0}" != "1" ]]; then
    return 0
  fi
  if ! ci_llvm_ready; then
    echo "bootstrap-m4-loop-probe: skipped (LLVM 9 not available)"
    return 0
  fi
  echo "bootstrap-m4-loop-probe (BOOTSTRAP_M4_LOOP_PROBE=1, --dry-run, issue #1498, #2058)..."
  if ! "$_CI_SCRIPT_DIR/bootstrap-loop-probe.sh" --dry-run; then
    echo "bootstrap-m4-loop-probe: failed — see docs/bootstrap-selfhost.md (#1498)" >&2
    return 1
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

# Dynamic $fn() JIT execute + lint (#1997, #2055); default on in ci-fast/ci-local when LLVM + MCJIT ready (#2060).
ci_run_jit_variable_function_compliance() {
  if [[ "${JIT_VARIABLE_FUNCTION_COMPLIANCE_GATE:-1}" != "1" ]]; then
    return 0
  fi
  if ! ci_llvm_ready; then
    echo "JIT variable-function compliance: skipped (LLVM 9 not available)"
    return 0
  fi
  if ! ci_should_run_jit; then
    echo "JIT variable-function compliance: skipped (JIT MCJIT probe failed)"
    return 0
  fi
  ci_apply_llvm_memory_env
  echo "PHPUnit: JIT variable-function compliance (VariableFunction*, #2060)..."
  ci_run_phpunit --filter VariableFunction --group jit --fail-on-skipped "$@"
}

# bin/jit.php $_SERVER / PATH_INFO refresh without recompile (issues #2257, #2275, #2292); default on — set JIT_SERVER_SUPERGLOBAL_GATE=0 to skip.
ci_run_jit_server_superglobal() {
  if [[ "${JIT_SERVER_SUPERGLOBAL_GATE:-1}" != "1" ]]; then
    return 0
  fi
  if ! ci_llvm_ready; then
    echo "PHPUnit: JitServerSuperglobal skipped (LLVM 9 not available)"
    return 0
  fi
  if ! ci_should_run_jit; then
    echo "PHPUnit: JitServerSuperglobal skipped (JIT MCJIT probe failed)"
    return 0
  fi
  ci_apply_llvm_memory_env
  echo "PHPUnit: JIT \$_SERVER refresh (JitServerSuperglobal; JIT_SERVER_SUPERGLOBAL_GATE=1, #2257, #2275)..."
  ci_run_phpunit --filter JitServerSuperglobal --fail-on-skipped "$@"
}

# 001-SimpleWeb (+ 003 when ready) phpc serve --jit superglobal curls (issue #2274); opt-in SERVE_JIT_SMOKE_GATE=1.
ci_run_examples_serve_jit_smoke() {
  if [[ "${SERVE_JIT_SMOKE_GATE:-0}" != "1" ]]; then
    return 0
  fi
  if [[ -n "${PHP_COMPILER_SKIP_SERVE_TESTS:-}" ]]; then
    echo "examples-serve-jit-smoke: skipped (PHP_COMPILER_SKIP_SERVE_TESTS is set)"
    return 0
  fi
  if ! ci_can_bind_loopback; then
    echo "examples-serve-jit-smoke: skipped (cannot bind loopback TCP)"
    return 0
  fi
  if ! ci_llvm_ready; then
    echo "examples-serve-jit-smoke: skipped (LLVM 9 not available)"
    return 0
  fi
  if ! ci_should_run_jit; then
    echo "examples-serve-jit-smoke: skipped (JIT MCJIT probe failed)"
    return 0
  fi
  ci_apply_llvm_memory_env
  echo "examples-serve-jit-smoke (SERVE_JIT_SMOKE_GATE=1, #2274)..."
  "$_CI_SCRIPT_DIR/examples-serve-jit-smoke.sh"
}

# 008-SelfHostProbe VM lint + run (issue #2240; default-on ci-fast #2343).
ci_run_examples_selfhostprobe_smoke() {
  if [[ "${EXAMPLES_SELFHOSTPROBE_SMOKE_GATE:-1}" != "1" ]]; then
    echo "examples-selfhostprobe-smoke: skipped (EXAMPLES_SELFHOSTPROBE_SMOKE_GATE=0)"
    return 0
  fi
  if [[ ! -f "${_CI_REPO_ROOT}/examples/008-SelfHostProbe/example.php" ]]; then
    echo "examples-selfhostprobe-smoke: skipped (008-SelfHostProbe tree missing #2207)"
    return 0
  fi
  echo "examples-selfhostprobe-smoke (EXAMPLES_SELFHOSTPROBE_SMOKE_GATE=1 default, #2343)..."
  "$_CI_SCRIPT_DIR/examples-selfhostprobe-smoke.sh"
}

# Shell curl harness for 006-FileUploadWeb multipart upload (issue #1999).
ci_run_file_upload_web_smoke() {
  if [[ "${FILE_UPLOAD_WEB_SMOKE_GATE:-1}" != "1" ]]; then
    return 0
  fi
  if [[ -n "${PHP_COMPILER_SKIP_SERVE_TESTS:-}" ]]; then
    echo "examples-web-smoke (006): skipped (PHP_COMPILER_SKIP_SERVE_TESTS is set)"
    return 0
  fi
  if ! ci_can_bind_loopback; then
    echo "examples-web-smoke (006): skipped (cannot bind loopback TCP)"
    return 0
  fi
  echo "examples-web-smoke (006): FileUploadWeb multipart curls (FILE_UPLOAD_WEB_SMOKE_GATE=1 default, #2009)..."
  "$_CI_SCRIPT_DIR/examples-web-smoke.sh" --fileupload-only
}

# Shell curl harness for 007-ThrowsWeb throw/catch POST (issue #2093, default-on #2125).
ci_run_throws_web_smoke() {
  if [[ "${THROWS_WEB_SMOKE_GATE:-1}" != "1" ]]; then
    return 0
  fi
  if [[ -n "${PHP_COMPILER_SKIP_SERVE_TESTS:-}" ]]; then
    echo "examples-web-smoke (007): skipped (PHP_COMPILER_SKIP_SERVE_TESTS is set)"
    return 0
  fi
  if ! ci_can_bind_loopback; then
    echo "examples-web-smoke (007): skipped (cannot bind loopback TCP)"
    return 0
  fi
  echo "examples-web-smoke (007): ThrowsWeb invalid POST curls (THROWS_WEB_SMOKE_GATE=1 default, #2125)..."
  "$_CI_SCRIPT_DIR/examples-web-smoke.sh" --throws-only
}

# Shell curl harness for 007-ThrowsWeb uncaught HTTP 500 (issue #2200, opt-in).
ci_run_throws_web_uncaught_smoke() {
  if [[ "${THROWSWEB_UNCAUGHT_500_GATE:-0}" != "1" ]]; then
    return 0
  fi
  if [[ -n "${PHP_COMPILER_SKIP_SERVE_TESTS:-}" ]]; then
    echo "examples-web-smoke (007 uncaught): skipped (PHP_COMPILER_SKIP_SERVE_TESTS is set)"
    return 0
  fi
  if ! ci_can_bind_loopback; then
    echo "examples-web-smoke (007 uncaught): skipped (cannot bind loopback TCP)"
    return 0
  fi
  echo "examples-web-smoke (007): ThrowsWeb uncaught HTTP 500 (THROWSWEB_UNCAUGHT_500_GATE=1, #2200)..."
  THROWSWEB_UNCAUGHT_500_GATE=1 "$_CI_SCRIPT_DIR/examples-web-smoke.sh" --throws-only
}

# Shell curl harness for 009-FastCGIWeb health + PATH_INFO (issue #2351).
ci_run_fastcgi_web_smoke() {
  if [[ "${FASTCGI_WEB_SMOKE_GATE:-0}" != "1" ]]; then
    return 0
  fi
  if [[ -n "${PHP_COMPILER_SKIP_SERVE_TESTS:-}" ]]; then
    echo "examples-web-smoke (009): skipped (PHP_COMPILER_SKIP_SERVE_TESTS is set)"
    return 0
  fi
  if ! ci_can_bind_loopback; then
    echo "examples-web-smoke (009): skipped (cannot bind loopback TCP)"
    return 0
  fi
  echo "examples-web-smoke (009): FastCGIWeb health + PATH_INFO curls (FASTCGI_WEB_SMOKE_GATE=1, #2351)..."
  "$_CI_SCRIPT_DIR/examples-web-smoke.sh" --fastcgi-only
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

# 005-SessionsWeb phpc serve --aot session flash (issue #2333); opt-in SESSIONS_WEB_SERVE_AOT_SMOKE_GATE=1.
ci_run_sessions_web_serve_aot_smoke() {
  if [[ "${SESSIONS_WEB_SERVE_AOT_SMOKE_GATE:-0}" != "1" ]]; then
    return 0
  fi
  if [[ -n "${PHP_COMPILER_SKIP_SERVE_TESTS:-}" ]]; then
    echo "examples-web-smoke (005 AOT serve): skipped (PHP_COMPILER_SKIP_SERVE_TESTS is set)"
    return 0
  fi
  if ! ci_can_bind_loopback; then
    echo "examples-web-smoke (005 AOT serve): skipped (cannot bind loopback TCP)"
    return 0
  fi
  if ! ci_llvm_ready; then
    echo "examples-web-smoke (005 AOT serve): skipped (LLVM 9 not available)"
    return 0
  fi
  echo "examples-web-smoke (005 AOT serve): SessionsWeb phpc serve --aot session flash (SESSIONS_WEB_SERVE_AOT_SMOKE_GATE=1, #2333)..."
  "$_CI_SCRIPT_DIR/examples-web-smoke.sh" --sessions-only --aot
}

# 006-FileUploadWeb phpc serve --aot multipart POST (issue #2333); opt-in FILE_UPLOAD_WEB_SERVE_AOT_SMOKE_GATE=1.
ci_run_file_upload_web_serve_aot_smoke() {
  if [[ "${FILE_UPLOAD_WEB_SERVE_AOT_SMOKE_GATE:-0}" != "1" ]]; then
    return 0
  fi
  if [[ -n "${PHP_COMPILER_SKIP_SERVE_TESTS:-}" ]]; then
    echo "examples-web-smoke (006 AOT serve): skipped (PHP_COMPILER_SKIP_SERVE_TESTS is set)"
    return 0
  fi
  if ! ci_can_bind_loopback; then
    echo "examples-web-smoke (006 AOT serve): skipped (cannot bind loopback TCP)"
    return 0
  fi
  if ! ci_llvm_ready; then
    echo "examples-web-smoke (006 AOT serve): skipped (LLVM 9 not available)"
    return 0
  fi
  echo "examples-web-smoke (006 AOT serve): FileUploadWeb phpc serve --aot multipart POST (FILE_UPLOAD_WEB_SERVE_AOT_SMOKE_GATE=1, #2333)..."
  "$_CI_SCRIPT_DIR/examples-web-smoke.sh" --fileupload-only --aot
}

# 007-ThrowsWeb phpc serve --aot caught invalid POST (issue #2387); opt-in THROWSWEB_SERVE_AOT_SMOKE_GATE=1.
ci_run_throws_web_serve_aot_smoke() {
  if [[ "${THROWSWEB_SERVE_AOT_SMOKE_GATE:-0}" != "1" ]]; then
    return 0
  fi
  if [[ -n "${PHP_COMPILER_SKIP_SERVE_TESTS:-}" ]]; then
    echo "examples-web-smoke (007 AOT serve): skipped (PHP_COMPILER_SKIP_SERVE_TESTS is set)"
    return 0
  fi
  if ! ci_can_bind_loopback; then
    echo "examples-web-smoke (007 AOT serve): skipped (cannot bind loopback TCP)"
    return 0
  fi
  if ! ci_llvm_ready; then
    echo "examples-web-smoke (007 AOT serve): skipped (LLVM 9 not available)"
    return 0
  fi
  echo "examples-web-smoke (007 AOT serve): ThrowsWeb phpc serve --aot caught invalid POST (THROWSWEB_SERVE_AOT_SMOKE_GATE=1, #2387)..."
  "$_CI_SCRIPT_DIR/examples-web-smoke.sh" --throws-only --aot
}

# 007-ThrowsWeb phpc serve --jit caught invalid POST (issue #2408); opt-in THROWSWEB_SERVE_JIT_SMOKE_GATE=1.
ci_run_throws_web_serve_jit_smoke() {
  if [[ "${THROWSWEB_SERVE_JIT_SMOKE_GATE:-0}" != "1" ]]; then
    return 0
  fi
  if [[ -n "${PHP_COMPILER_SKIP_SERVE_TESTS:-}" ]]; then
    echo "examples-web-smoke (007 JIT serve): skipped (PHP_COMPILER_SKIP_SERVE_TESTS is set)"
    return 0
  fi
  if ! ci_can_bind_loopback; then
    echo "examples-web-smoke (007 JIT serve): skipped (cannot bind loopback TCP)"
    return 0
  fi
  if ! ci_llvm_ready; then
    echo "examples-web-smoke (007 JIT serve): skipped (LLVM 9 not available)"
    return 0
  fi
  if ! ci_should_run_jit; then
    echo "examples-web-smoke (007 JIT serve): skipped (JIT MCJIT probe failed)"
    return 0
  fi
  ci_apply_llvm_memory_env
  echo "examples-web-smoke (007 JIT serve): ThrowsWeb phpc serve --jit caught invalid POST (THROWSWEB_SERVE_JIT_SMOKE_GATE=1, #2408)..."
  "$_CI_SCRIPT_DIR/examples-web-smoke.sh" --throws-only --jit
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
  if [[ "${FILE_UPLOAD_WEB_DEPLOY_SMOKE_GATE:-0}" == "1" ]]; then
    "$_CI_SCRIPT_DIR/deploy-smoke.sh" --example 006
  fi
  if [[ "${THROWSWEB_DEPLOY_SMOKE_GATE:-0}" == "1" ]]; then
    "$_CI_SCRIPT_DIR/deploy-smoke.sh" --example 007
  fi
  if [[ "${FASTCGI_WEB_DEPLOY_SMOKE_GATE:-0}" == "1" ]]; then
    "$_CI_SCRIPT_DIR/deploy-smoke.sh" --example 009
  fi
}

# @group aot-link PHPUnit (link-only; execute is ci_run_miniwebapp_aot_execute — #775).
# 005 link: ExamplesCompileTest::test005SessionsWebAotLink when SESSIONS_WEB_AOT_LINK_GATE=1 (#1946).
ci_run_aot_link_phpunit() {
  local -a aot_link_args=(--group aot-link --exclude-group serve --exclude-group miniwebapp-aot-execute --exclude-group miniwebapp-aot-serve --exclude-group sessionsweb-aot-execute --exclude-group fileuploadweb-aot-execute --exclude-group throwsweb-aot-execute --exclude-group fastcgiweb-aot-execute --exclude-group selfhostprobe-aot-execute)
  echo "PHPUnit: AOT link (@group aot-link; SESSIONS_WEB_AOT_LINK_GATE=${SESSIONS_WEB_AOT_LINK_GATE:-0}, FILE_UPLOAD_WEB_AOT_LINK_GATE=${FILE_UPLOAD_WEB_AOT_LINK_GATE:-0}, THROWSWEB_AOT_LINK_GATE=${THROWSWEB_AOT_LINK_GATE:-1})..."
  ci_run_phpunit "${aot_link_args[@]}" "$@"
}

# 006-FileUploadWeb AOT binary CLI execute (issue #1999); default FILE_UPLOAD_WEB_AOT_SMOKE_GATE=1 (#2012).
ci_run_file_upload_web_aot_execute() {
  if [[ "${FILE_UPLOAD_WEB_AOT_SMOKE_GATE:-1}" != "1" ]]; then
    return 0
  fi
  if ! ci_llvm_ready; then
    echo "PHPUnit: FileUploadWeb AOT execute skipped (LLVM 9 not available)"
    return 0
  fi
  echo "PHPUnit: FileUploadWeb AOT execute (@group fileuploadweb-aot-execute; FILE_UPLOAD_WEB_AOT_SMOKE_GATE=1, #1999)..."
  ci_run_phpunit --group fileuploadweb-aot-execute "$@"
}

# 007-ThrowsWeb AOT binary CLI execute (issue #2101); default THROWSWEB_AOT_SMOKE_GATE=1 (#2135).
ci_run_throws_web_aot_execute() {
  if [[ "${THROWSWEB_AOT_SMOKE_GATE:-1}" != "1" ]]; then
    return 0
  fi
  if ! ci_llvm_ready; then
    echo "PHPUnit: ThrowsWeb AOT execute skipped (LLVM 9 not available)"
    return 0
  fi
  echo "PHPUnit: ThrowsWeb AOT execute (@group throwsweb-aot-execute; THROWSWEB_AOT_SMOKE_GATE=1, #2101)..."
  ci_run_phpunit --group throwsweb-aot-execute "$@"
}

# 008-SelfHostProbe AOT binary CLI execute (issue #2407); default SELFHOSTPROBE_AOT_SMOKE_GATE=1.
ci_run_selfhostprobe_aot_smoke() {
  if [[ "${SELFHOSTPROBE_AOT_SMOKE_GATE:-1}" != "1" ]]; then
    return 0
  fi
  if ! ci_llvm_ready; then
    echo "PHPUnit: SelfHostProbe AOT execute skipped (LLVM 9 not available)"
    return 0
  fi
  echo "PHPUnit: SelfHostProbe AOT execute (@group selfhostprobe-aot-execute; SELFHOSTPROBE_AOT_SMOKE_GATE=1, #2407)..."
  ci_run_phpunit --group selfhostprobe-aot-execute "$@"
}

# 009-FastCGIWeb AOT binary CLI execute (issue #2331); opt-in FASTCGI_WEB_AOT_SMOKE_GATE=1 (#2352).
ci_run_fastcgi_web_aot_execute() {
  if [[ "${FASTCGI_WEB_AOT_SMOKE_GATE:-0}" != "1" ]]; then
    return 0
  fi
  if ! ci_llvm_ready; then
    echo "PHPUnit: FastCGIWeb AOT execute skipped (LLVM 9 not available)"
    return 0
  fi
  echo "PHPUnit: FastCGIWeb AOT execute (@group fastcgiweb-aot-execute; FASTCGI_WEB_AOT_SMOKE_GATE=1, #2352)..."
  ci_run_phpunit --group fastcgiweb-aot-execute "$@"
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
