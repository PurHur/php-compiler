#!/usr/bin/env bash
# North Star 5 presenter verify — M5 vendor prelink + self-host cold-boot ladder (#1492, #1416).
#
#   ./script/north-star5-verify.sh
#   make north-star5-verify
#
# Order: inventory → spine 716/717 → vendor bundles → selfhost link → vendor objects (partial OK)
#        → optional north-star4 dry-run when LLVM present.
#
# Default exits 0 when vendor prelink is partial (legacy); --strict requires 3/3 object_ok.
# Use --strict to require all three vendor packages object_ok.
set -euo pipefail

# shellcheck source=ci-common.sh
source "$(dirname "$0")/ci-common.sh"

ci_cd_repo

REQUIRE_LLVM=0
STRICT_M5=0
RUN_NS4=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --require-llvm) REQUIRE_LLVM=1; shift ;;
    --strict) STRICT_M5=1; shift ;;
    --with-ns4) RUN_NS4=1; shift ;;
    -h|--help)
      cat <<'EOF'
Usage: script/north-star5-verify.sh [--require-llvm] [--strict] [--with-ns4]

M5 ladder presenter (#1416, #1492):

  1. php script/bootstrap-inventory.php --check
  2. php script/bootstrap-spine-count.php (expect 717/717)
  3. php script/bootstrap-vendor-objects.php --check
  4. make bootstrap-selfhost-probe + bootstrap-selfhost-lib-spine-smoke (LLVM)
  5. php script/bootstrap-vendor-objects.php --compile (partial OK unless --strict)
  6. Optional: ./script/north-star4-verify.sh --dry-run-only (--with-ns4)

Options:
  --require-llvm   fail if LLVM 9 missing (default: skip LLVM steps, exit 0)
  --strict         fail unless all vendor packages reach object_ok
  --with-ns4       also run north-star4-verify --dry-run-only when LLVM present

Docker:
  ./script/docker-exec.sh -- bash -lc './script/north-star5-verify.sh'
EOF
      exit 0
      ;;
    *) echo "north-star5-verify: unknown argument: $1" >&2; exit 1 ;;
  esac
done

ns5_hint() {
  local step="$1"
  case "${step}" in
    1) echo "Next: php script/bootstrap-inventory.php" ;;
    2) echo "Next: grow compiler_lib_spine_smoke toward 717/717 (#2652)" ;;
    3) echo "Next: php script/bootstrap-vendor-objects.php" ;;
    4) echo "Next: make bootstrap-selfhost-probe (LLVM 9)" ;;
    5) echo "Next: PHP_COMPILER_VENDOR_PRELINK=1 vendor AOT; fix php-cfg/php-llvm parse (#1416)" ;;
    *) echo "Next: docs/bootstrap-m5-fast-path.md · #1416 · #1492" ;;
  esac
}

ns5_run() {
  local step="$1"
  local label="$2"
  shift 2
  echo
  echo "=== north-star5-verify step ${step}: ${label} ==="
  if "$@"; then
    echo "north-star5-verify: step ${step} ok"
    return 0
  fi
  echo "north-star5-verify: step ${step} FAILED (${label})" >&2
  ns5_hint "${step}" >&2
  exit 1
}

ns5_print_summary() {
  local vendor_ok="${1:-0}"
  echo
  echo "north-star5-verify: M5 status"
  echo "  Spine: 717/717 (Phase A inventory SSOT)"
  echo "  Vendor prelink: ${vendor_ok}/3 object_ok (php-cfg, php-types, php-llvm prelinked .o)"
  echo "  Cold boot: Zend still drives bin/compile.php — target is compiled driver + prelinked vendor"
  echo "north-star5-verify: Next — shrink self-host stubs; link spine + prelinked .o; retire vendor/autoload (#1416)"
}

if [[ ! -x "${_CI_REPO_ROOT}/phpc" ]]; then
  echo "north-star5-verify: phpc wrapper missing; run composer install" >&2
  exit 1
fi

ci_prepare_test_runtime
ci_install_deps

ns5_run 1 "bootstrap inventory --check" \
  ci_ensure_generated_doc "${_CI_REPO_ROOT}/script/bootstrap-inventory.php" "${_CI_REPO_ROOT}/docs/bootstrap-inventory.md"

echo
echo "=== north-star5-verify step 2: spine count (717/717) ==="
SPINE_LINE="$("${PHP_BIN}" "${PHP_OPTS[@]}" "${_CI_REPO_ROOT}/script/bootstrap-spine-count.php" 2>/dev/null | tail -n 1 || true)"
echo "${SPINE_LINE}"
if [[ "${SPINE_LINE}" != *"717/717"* ]]; then
  echo "north-star5-verify: step 2 FAILED (expected bootstrap-spine-count: 717/717)" >&2
  ns5_hint 2 >&2
  exit 1
fi
echo "north-star5-verify: step 2 ok"

echo
echo "=== north-star5-verify step 3: vendor prelink bundles --check ==="
if ! "${PHP_BIN}" "${PHP_OPTS[@]}" "${_CI_REPO_ROOT}/script/bootstrap-vendor-objects.php" --check; then
  echo "north-star5-verify: vendor bundles stale; regenerating..."
  "${PHP_BIN}" "${PHP_OPTS[@]}" "${_CI_REPO_ROOT}/script/bootstrap-vendor-objects.php" >/dev/null
  "${PHP_BIN}" "${PHP_OPTS[@]}" "${_CI_REPO_ROOT}/script/bootstrap-vendor-objects.php" --check
fi
echo "north-star5-verify: step 3 ok"

if ! ci_llvm_ready; then
  if [[ "${REQUIRE_LLVM}" -eq 1 ]]; then
    echo "north-star5-verify: LLVM 9 required (--require-llvm) but not found" >&2
    exit 1
  fi
  echo
  echo "=== north-star5-verify: steps 4–5 skipped (LLVM 9 not available) ==="
  ns5_print_summary 0
  echo "north-star5-verify: OK (partial — no LLVM)"
  exit 0
fi

ci_apply_llvm_memory_env

ns5_run 4a "selfhost compile probe" make -C "${_CI_REPO_ROOT}" bootstrap-selfhost-probe
ns5_run 4b "lib spine smoke link" make -C "${_CI_REPO_ROOT}" bootstrap-selfhost-lib-spine-smoke

echo
echo "=== north-star5-verify step 5: vendor prelink AOT (--compile) ==="
VENDOR_OK=0
set +e
"${PHP_BIN}" "${PHP_OPTS[@]}" "${_CI_REPO_ROOT}/script/bootstrap-vendor-objects.php" --compile
vendor_code=$?
set -e
if [[ -f "${_CI_REPO_ROOT}/prelinked/bootstrap-vendor/manifest.json" ]]; then
  VENDOR_OK="$("${PHP_BIN}" -r '
    $m = json_decode(file_get_contents($argv[1]), true);
    $n = 0;
    foreach (($m["packages"] ?? []) as $p) {
      if (($p["status"] ?? "") === "object_ok") { ++$n; }
    }
    echo $n;
  ' "${_CI_REPO_ROOT}/prelinked/bootstrap-vendor/manifest.json")"
fi
echo "north-star5-verify: vendor object_ok count=${VENDOR_OK}/3 (exit ${vendor_code})"
if [[ "${STRICT_M5}" -eq 1 && "${VENDOR_OK}" -lt 3 ]]; then
  echo "north-star5-verify: step 5 FAILED (--strict requires 3/3 object_ok)" >&2
  ns5_hint 5 >&2
  exit 1
fi
if [[ "${vendor_code}" -ne 0 && "${STRICT_M5}" -eq 1 ]]; then
  exit "${vendor_code}"
fi
echo "north-star5-verify: step 5 ok (partial allowed)"

if [[ "${RUN_NS4}" -eq 1 ]]; then
  echo
  echo "=== north-star5-verify step 6: north-star4 dry-run (--with-ns4) ==="
  "${_CI_SCRIPT_DIR}/north-star4-verify.sh" --dry-run-only || true
fi

ns5_print_summary "${VENDOR_OK}"
echo "north-star5-verify: OK"
exit 0
