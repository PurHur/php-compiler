#!/usr/bin/env bash
# North Star 5 presenter verify — M5 vendor prelink + self-host cold-boot ladder (#1492, #1416).
#
#   ./script/north-star5-verify.sh --fast     # default development / PR gate (~1–2 min)
#   make north-star5-verify-fast
#   make north-star5-verify ARGS=--strict     # pre-merge bootstrap only (~1h)
#
# Order (full path): inventory → spine → vendor bundles → selfhost link → vendor objects (partial OK)
#        → optional north-star4 dry-run when LLVM present.
#
# Default exits 0 when vendor prelink is partial (php-types object_ok; cfg/llvm blocked on parse).
# Use --strict to require all three vendor packages object_ok (full spine relink + vendor AOT; ~1h).
# Use --fast for PR feedback (~1–2 min): inventory + spine + committed prelink blobs + VM probe (no relink).
set -euo pipefail

# shellcheck source=ci-common.sh
source "$(dirname "$0")/ci-common.sh"

ci_cd_repo

REQUIRE_LLVM=0
STRICT_M5=0
FAST_M5=0
RUN_NS4=0
# -1 = auto (no Zend when prelinked gen-0 seed exists), 0 = allow Zend, 1 = forbid Zend
NS5_NO_ZEND=-1
if [[ "${NORTH_STAR5_VERIFY_FAST:-0}" == "1" ]]; then
  FAST_M5=1
fi
while [[ $# -gt 0 ]]; do
  case "$1" in
    --require-llvm) REQUIRE_LLVM=1; shift ;;
    --strict) STRICT_M5=1; shift ;;
    --fast) FAST_M5=1; shift ;;
    --with-ns4) RUN_NS4=1; shift ;;
    --zend-gen0) NS5_NO_ZEND=0; shift ;;
    -h|--help)
      cat <<'EOF'
Usage: script/north-star5-verify.sh [--fast] [--require-llvm] [--strict] [--with-ns4]

M5 ladder presenter (#1416, #1492):

  1. php script/bootstrap-inventory.php --check
  2. php script/bootstrap-spine-count.php + check-selfhost-spine-coverage-sync.php (live N/N)
  3. php script/bootstrap-vendor-objects.php --check
  4. make bootstrap-selfhost-probe + bootstrap-selfhost-lib-spine-smoke (LLVM)
  4c. spine link+run with vendor/ absent when prelinked .o present (#3052)
  5. php script/bootstrap-vendor-objects.php --compile (partial OK unless --strict)
  6. Optional: ./script/north-star4-verify.sh --dry-run-only (--with-ns4)

Fast path (--fast, ~1–2 min; for PRs / daily iteration):
  Steps 1–3 + prelinked gen-0/vendor blobs + VM driver execute probe + spine bundle OK run.
  No spine relink, no vendor --compile. ci-local already runs most of this via individual gates.

Full path (--strict, ~1h; before merging bootstrap/M5 work):
  All steps above including native driver build, spine relink, vendor AOT, cold boot without vendor/.

Options:
  --fast           PR feedback loop — committed prelink blobs + probes only (no relink/AOT)
  --require-llvm   fail if LLVM 9 missing (default: skip LLVM steps, exit 0)
  --strict         full ladder + fail unless all vendor packages reach object_ok
  --with-ns4       also run north-star4-verify --dry-run-only when LLVM present
  --zend-gen0      allow Zend gen-0 bootstrap (default: no Zend when seed present)

Env:
  NORTH_STAR5_VERIFY_FAST=1   same as --fast
  BOOTSTRAP_VENDOR_REBUILD_AUDIT=1   opt-in native vendor .o drift audit (#8718)

Docker:
  ./script/docker-exec.sh -- bash -lc './script/north-star5-verify.sh --fast'
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
    2) echo "Next: php script/check-selfhost-spine-coverage-sync.php (#2543 cli_spine_shim)" ;;
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

ns5_spine_ratio_label() {
  local json spine
  json="$("${PHP_BIN}" "${PHP_OPTS[@]}" "${_CI_REPO_ROOT}/script/bootstrap-spine-count.php" --json 2>/dev/null)" || json='{"spine":725,"inventory":725}'
  spine="$(php -r '$j=json_decode($argv[1],true); echo (int)($j["spine"]??725);' "${json}")"
  echo "${spine}/${spine}"
}

ns5_prelinked_vendor_ready() {
  local manifest="${_CI_REPO_ROOT}/prelinked/bootstrap-vendor/manifest.json"
  local slug o ok=0
  [[ -f "${manifest}" ]] || return 1
  for slug in ircmaxell-php-cfg ircmaxell-php-types ircmaxell-php-llvm; do
    o="${_CI_REPO_ROOT}/prelinked/bootstrap-vendor/${slug}.o"
    [[ -f "${o}" ]] && ok=$((ok + 1))
  done
  [[ "${ok}" -eq 3 ]]
}

ns5_has_gen0_seed() {
  [[ -x "${_CI_REPO_ROOT}/prelinked/bootstrap-gen0/bin-compile-aot" ]] \
    && [[ -f "${_CI_REPO_ROOT}/prelinked/bootstrap-gen0/manifest.json" ]]
}

ns5_vendor_object_ok_count() {
  local manifest="${_CI_REPO_ROOT}/prelinked/bootstrap-vendor/manifest.json"
  if [[ ! -f "${manifest}" ]]; then
    echo 0
    return 0
  fi
  "${PHP_BIN}" -r '
    $m = json_decode(file_get_contents($argv[1]), true);
    $n = 0;
    foreach (($m["packages"] ?? []) as $p) {
      if (($p["status"] ?? "") === "object_ok") { ++$n; }
    }
    echo $n;
  ' "${manifest}"
}

ns5_fast_ensure_spine_binary() {
  local bin="${_CI_REPO_ROOT}/build/selfhost-lib-spine-smoke"
  if [[ -x "${bin}" ]]; then
    return 0
  fi
  # shellcheck source=bootstrap-gen0-install-prelinked-driver.sh
  source "${_CI_SCRIPT_DIR}/bootstrap-gen0-install-prelinked-driver.sh"
  ROOT="${_CI_REPO_ROOT}" bootstrap_copy_prelinked_compiler_lib_spine_blob "${bin}"
}

ns5_print_summary() {
  local vendor_ok="${1:-0}"
  local spine_ratio
  spine_ratio="$(ns5_spine_ratio_label)"
  echo
  echo "north-star5-verify: M5 status"
  echo "  Spine: ${spine_ratio} (Phase A inventory SSOT)"
  echo "  Vendor prelink: ${vendor_ok}/3 object_ok (php-cfg, php-types, php-llvm prelinked .o)"
  if [[ "${NS5_NO_ZEND}" -eq 1 ]]; then
    echo "  Cold boot: prelinked/bootstrap-gen0/bin-compile-aot when build/ empty (BOOTSTRAP_M5_NO_ZEND=1, #3053)"
  else
    echo "  Cold boot: Zend gen-0 allowed (--zend-gen0; target is prelinked gen-0 + vendor .o, #3053)"
  fi
  echo "north-star5-verify: Next — shrink self-host stubs; link spine + prelinked .o; retire vendor/autoload (#1416)"
}

if [[ ! -x "${_CI_REPO_ROOT}/phpc" ]]; then
  echo "north-star5-verify: phpc wrapper missing; run composer install" >&2
  exit 1
fi

ci_prepare_test_runtime
ci_install_deps

if [[ "${NS5_NO_ZEND}" -eq -1 ]]; then
  if ns5_has_gen0_seed; then
    NS5_NO_ZEND=1
  else
    NS5_NO_ZEND=0
  fi
fi

ns5_run 1 "bootstrap inventory --check" \
  ci_ensure_generated_doc "${_CI_REPO_ROOT}/script/bootstrap-inventory.php" "${_CI_REPO_ROOT}/docs/bootstrap-inventory.md"

SPINE_RATIO="$(ns5_spine_ratio_label)"
echo
echo "=== north-star5-verify step 2: spine coverage (${SPINE_RATIO}) ==="
SPINE_LINE="$("${PHP_BIN}" "${PHP_OPTS[@]}" "${_CI_REPO_ROOT}/script/bootstrap-spine-count.php" 2>/dev/null | tail -n 1 || true)"
echo "${SPINE_LINE}"
if ! "${PHP_BIN}" "${PHP_OPTS[@]}" "${_CI_REPO_ROOT}/script/check-selfhost-spine-coverage-sync.php"; then
  echo "north-star5-verify: step 2 FAILED (spine must cover all Phase A inventory files)" >&2
  ns5_hint 2 >&2
  exit 1
fi
echo "north-star5-verify: step 2 ok"

echo
echo "=== north-star5-verify step 3: vendor prelink bundles --check ==="
if ! "${PHP_BIN}" "${PHP_OPTS[@]}" "${_CI_REPO_ROOT}/script/bootstrap-vendor-objects.php" --check; then
  if [[ ! -d "${_CI_REPO_ROOT}/vendor/ircmaxell/php-cfg" ]]; then
    echo "north-star5-verify: vendor tree absent — cold boot bundle check failed" >&2
    ns5_hint 3 >&2
    exit 1
  fi
  echo "north-star5-verify: vendor bundles stale; regenerating..."
  "${PHP_BIN}" "${PHP_OPTS[@]}" "${_CI_REPO_ROOT}/script/bootstrap-vendor-objects.php" >/dev/null
  "${PHP_BIN}" "${PHP_OPTS[@]}" "${_CI_REPO_ROOT}/script/bootstrap-vendor-objects.php" --check
fi
echo "north-star5-verify: step 3 ok"

if [[ "${FAST_M5}" -eq 1 ]]; then
  echo
  echo "=== north-star5-verify: fast path (no spine relink / vendor AOT) ==="
  if ! ns5_has_gen0_seed; then
    echo "north-star5-verify: step 4f FAILED (prelinked/bootstrap-gen0 seed missing)" >&2
    ns5_hint 4 >&2
    exit 1
  fi
  echo "north-star5-verify: step 4f ok (prelinked gen-0 seed present)"
  if ! "${PHP_BIN}" "${PHP_OPTS[@]}" "${_CI_REPO_ROOT}/script/check-bootstrap-gen0-manifest-sync.php"; then
    echo "north-star5-verify: step 4f-m FAILED (gen-0 manifest/driver mismatch — #8713)" >&2
    exit 1
  fi
  echo "north-star5-verify: step 4f-m ok (gen-0 manifest matches committed driver)"

  if ! ns5_prelinked_vendor_ready; then
    echo "north-star5-verify: step 4f-v FAILED (prelinked/bootstrap-vendor/*.o missing)" >&2
    ns5_hint 3 >&2
    exit 1
  fi
  echo "north-star5-verify: step 4f-v ok (prelinked vendor .o present)"

  VENDOR_OK="$(ns5_vendor_object_ok_count)"
  if [[ "${STRICT_M5}" -eq 1 && "${VENDOR_OK}" -lt 3 ]]; then
    echo "north-star5-verify: step 5f FAILED (--strict requires 3/3 object_ok in manifest; have ${VENDOR_OK}/3)" >&2
    ns5_hint 5 >&2
    exit 1
  fi
  echo "north-star5-verify: step 5f ok (vendor manifest ${VENDOR_OK}/3 object_ok, no --compile)"

  if ci_llvm_ready; then
    ci_apply_llvm_memory_env
    ns5_run 4f2 "VM driver execute probe (fast)" make -C "${_CI_REPO_ROOT}" bootstrap-selfhost-vm-driver-execute-probe
    echo
    echo "=== north-star5-verify step 4f3: spine bundle OK (prelinked blob, no relink) ==="
    if ! ns5_fast_ensure_spine_binary; then
      echo "north-star5-verify: step 4f3 FAILED (no spine binary and prelinked blob missing)" >&2
      ns5_hint 4 >&2
      exit 1
    fi
    spine_fast_ok=0
    for spine_fast_attempt in 1 2; do
      spine_fast_output="$("${_CI_REPO_ROOT}/build/selfhost-lib-spine-smoke" 2>&1 || true)"
      if printf '%s' "${spine_fast_output}" | grep -q 'compiler_lib_spine_smoke bundle OK'; then
        spine_fast_ok=1
        break
      fi
      if [[ -n "${spine_fast_output}" ]]; then
        printf '%s\n' "${spine_fast_output}" >&2
      fi
      if [[ "${spine_fast_attempt}" -lt 2 ]]; then
        echo "north-star5-verify: step 4f3 retry ${spine_fast_attempt}/2 (spine bundle transient failure)" >&2
        sleep 1
      fi
    done
    if [[ "${spine_fast_ok}" -ne 1 ]]; then
      echo "north-star5-verify: step 4f3 FAILED (spine bundle OK run)" >&2
      exit 1
    fi
    echo "north-star5-verify: step 4f3 ok"
  else
    if [[ "${REQUIRE_LLVM}" -eq 1 ]]; then
      echo "north-star5-verify: LLVM 9 required (--require-llvm) but not found" >&2
      exit 1
    fi
    echo "north-star5-verify: steps 4f2–4f3 skipped (LLVM 9 not available)"
  fi

  if [[ "${BOOTSTRAP_VENDOR_REBUILD_AUDIT:-0}" == "1" ]]; then
    echo
    echo "=== north-star5-verify step 4f-a: vendor native rebuild audit (#8718) ==="
    if ci_llvm_ready; then
      ci_apply_llvm_memory_env
      if ! "${_CI_SCRIPT_DIR}/bootstrap-vendor-native-rebuild-audit.sh"; then
        echo "north-star5-verify: step 4f-a FAILED (vendor prelink drift — see audit output)" >&2
        exit 1
      fi
      echo "north-star5-verify: step 4f-a ok"
    else
      echo "north-star5-verify: step 4f-a skipped (LLVM 9 not available; set BOOTSTRAP_VENDOR_REBUILD_AUDIT=0 to silence)"
    fi
  fi

  ns5_print_summary "${VENDOR_OK}"
  echo "north-star5-verify: OK (fast — use --strict before merging bootstrap/M5 work)"
  exit 0
fi

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
if [[ "${STRICT_M5}" -eq 1 ]]; then
  ns5_run 4a2 "build compiled argv driver (bin-compile-aot)" make -C "${_CI_REPO_ROOT}" bootstrap-selfhost-driver-smoke
  ns5_run 4b "lib spine smoke link" env BOOTSTRAP_NO_ZEND_FALLBACK=1 make -C "${_CI_REPO_ROOT}" bootstrap-selfhost-lib-spine-smoke
else
  ns5_run 4b "lib spine smoke link" make -C "${_CI_REPO_ROOT}" bootstrap-selfhost-lib-spine-smoke
fi

echo
echo "=== north-star5-verify step 4c: lib spine smoke run (vendor/ absent, prelinked .o — #3052) ==="
SPINE_BIN="${_CI_REPO_ROOT}/build/selfhost-lib-spine-smoke"
if [[ ! -x "${SPINE_BIN}" ]]; then
  echo "north-star5-verify: step 4c FAILED (missing ${SPINE_BIN}; run step 4b)" >&2
  ns5_hint 4 >&2
  exit 1
fi
if ! ns5_prelinked_vendor_ready; then
  echo "north-star5-verify: step 4c skipped (prelinked/bootstrap-vendor/*.o missing)" >&2
else
  if [[ "${NS5_NO_ZEND}" -eq 1 ]]; then
    ns5_run 4c0 "native compile driver for vendor-absent spine (no Zend gen-0)" env BOOTSTRAP_M5_NO_ZEND=1 make -C "${_CI_REPO_ROOT}" bootstrap-selfhost-link
  else
    ns5_run 4c0 "native compile driver for vendor-absent spine" make -C "${_CI_REPO_ROOT}" bootstrap-selfhost-link
  fi
  vendor_spine_bak=""
  if [[ -d "${_CI_REPO_ROOT}/vendor" ]]; then
    vendor_spine_bak="${_CI_REPO_ROOT}/.north-star5-vendor-spine.bak"
    rm -rf "${vendor_spine_bak}"
    mv "${_CI_REPO_ROOT}/vendor" "${vendor_spine_bak}"
  fi
  set +e
  env VENDOR_TREE_ABSENT=1 BOOTSTRAP_NO_ZEND_FALLBACK=1 make -C "${_CI_REPO_ROOT}" bootstrap-selfhost-lib-spine-smoke >/dev/null
  spine_relink=$?
  set -e
  if [[ -n "${vendor_spine_bak}" ]]; then
    mv "${vendor_spine_bak}" "${_CI_REPO_ROOT}/vendor"
  fi
  if [[ "${spine_relink}" -ne 0 || ! -x "${SPINE_BIN}" ]]; then
    echo "north-star5-verify: step 4c FAILED (spine link with vendor/ absent — #3052)" >&2
    ns5_hint 4 >&2
    exit 1
  fi
  if ! "${SPINE_BIN}" | grep -q 'compiler_lib_spine_smoke bundle OK'; then
    echo "north-star5-verify: step 4c FAILED (spine binary run without vendor/)" >&2
    exit 1
  fi
  echo "north-star5-verify: step 4c ok (725-file spine, prelinked vendor .o only)"
fi

echo
echo "=== north-star5-verify step 5: vendor prelink AOT (--compile) ==="
VENDOR_OK=0
set +e
# Strict: step 4a2 leaves a native argv driver; vendor AOT must use it (no Zend loop — #3028).
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

echo
echo "=== north-star5-verify step 5b: vendor cold boot (--compile, vendor/ absent) ==="
if [[ -d "${_CI_REPO_ROOT}/vendor" ]]; then
  vendor_bak="${_CI_REPO_ROOT}/.north-star5-vendor.bak"
  rm -rf "${vendor_bak}"
  mv "${_CI_REPO_ROOT}/vendor" "${vendor_bak}"
  set +e
  "${PHP_BIN}" "${PHP_OPTS[@]}" "${_CI_REPO_ROOT}/script/bootstrap-vendor-objects.php" --compile
  cold_code=$?
  set -e
  mv "${vendor_bak}" "${_CI_REPO_ROOT}/vendor"
  if [[ "${cold_code}" -ne 0 ]]; then
    echo "north-star5-verify: step 5b FAILED (cold boot vendor prelink — #2865)" >&2
    ns5_hint 5 >&2
    exit 1
  fi
  echo "north-star5-verify: step 5b ok (3/3 committed .o, no vendor/)"
else
  set +e
  "${PHP_BIN}" "${PHP_OPTS[@]}" "${_CI_REPO_ROOT}/script/bootstrap-vendor-objects.php" --compile
  cold_code=$?
  set -e
  if [[ "${cold_code}" -ne 0 ]]; then
    echo "north-star5-verify: step 5b FAILED (vendor/ already absent)" >&2
    exit 1
  fi
  echo "north-star5-verify: step 5b ok (vendor/ absent)"
fi

if [[ "${RUN_NS4}" -eq 1 ]]; then
  echo
  echo "=== north-star5-verify step 6: north-star4 dry-run (--with-ns4) ==="
  "${_CI_SCRIPT_DIR}/north-star4-verify.sh" --dry-run-only || true
fi

ns5_print_summary "${VENDOR_OK}"
echo "north-star5-verify: OK"
exit 0
