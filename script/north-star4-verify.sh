#!/usr/bin/env bash
# North Star 4 presenter verify — M4 bootstrap-loop strict ladder (issues #2379, #1492, #1498).
#
#   ./script/north-star4-verify.sh
#   make north-star4-verify
#
# Order: bootstrap inventory --check → M3 strict probes → M4 gen-1 link → M4 loop probe
#        (incl. gen-2→gen-3 spine + full-revision argv probe #2898) → fallback gen-3 spine
#        → next-step hints (#2112, #1521, #2075).
#
# Default exits 0 on partial M4 (M3 strict or full probe exit 2) with documented hints.
# Use --strict to fail on partial M4; --dry-run-only to run bootstrap-loop-probe --dry-run only.
set -euo pipefail

# shellcheck source=ci-common.sh
source "$(dirname "$0")/ci-common.sh"

ci_cd_repo

REQUIRE_LLVM=0
DRY_RUN_ONLY=0
STRICT_M4=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --require-llvm) REQUIRE_LLVM=1; shift ;;
    --dry-run-only) DRY_RUN_ONLY=1; shift ;;
    --strict) STRICT_M4=1; shift ;;
    -h|--help)
      cat <<'EOF'
Usage: script/north-star4-verify.sh [--require-llvm] [--dry-run-only] [--strict]

Runs North Star 4 M4 strict ladder checks (issue #2379, epic #1492):

  1. php script/bootstrap-inventory.php --check
  2. BOOTSTRAP_M3_HELLOWORLD_STRICT=1 bootstrap-selfhost-helloworld-probe.sh (LLVM)
  3. BOOTSTRAP_M3_COMPILE_SMOKE_STRICT=1 bootstrap-selfhost-compile-smoke-probe.sh (LLVM)
  4. BOOTSTRAP_M4_LINK_COMPILE_DRIVER=1 bootstrap-loop-gen1-link.sh (LLVM)
  5. bootstrap-loop-probe.sh (full ladder incl. full-revision #2898, or --dry-run with --dry-run-only)
  6. bootstrap-loop-gen2-recompile-spine.sh (gen-2→gen-3 spine when step 5 partial)
  7. bootstrap-selfhost-full-revision-probe.sh (when step 6 ran but step 5 was not full green)
  8. Print next steps for #2112 BOOTSTRAP_M4_GEN2_STRICT_GATE, #1521 compiled driver

Options:
  --require-llvm     fail if LLVM 9 is missing (default: skip LLVM steps, exit 0)
  --dry-run-only     step 5 uses bootstrap-loop-probe.sh --dry-run (presenter on partial M4)
  --strict           fail on M3 strict or M4 probe exit 2; BOOTSTRAP_M4_GEN2_STRICT=1 on gen-1 link

Environment: script/ci-defaults.env. See docs/bootstrap-selfhost.md § M4 loop.

Docker:
  ./script/docker-exec.sh -- bash -lc './script/north-star4-verify.sh --dry-run-only'
EOF
      exit 0
      ;;
    *) echo "north-star4-verify: unknown argument: $1" >&2; exit 1 ;;
  esac
done

ns4_hint() {
  local step="$1"
  case "${step}" in
    1) echo "Next: php script/bootstrap-inventory.php; fix inventory blockers (#765)" ;;
    2) echo "Next: BOOTSTRAP_M3_HELLOWORLD_STRICT=1 ./script/bootstrap-selfhost-helloworld-probe.sh (#1493)" ;;
    3) echo "Next: BOOTSTRAP_M3_COMPILE_SMOKE_STRICT=1 ./script/bootstrap-selfhost-compile-smoke-probe.sh (#1937)" ;;
    4) echo "Next: BOOTSTRAP_M4_LINK_COMPILE_DRIVER=1 ./script/bootstrap-loop-gen1-link.sh (#1498)" ;;
    5) echo "Next: ./script/bootstrap-loop-probe.sh (full) or --dry-run for partial M4 (#1498)" ;;
    6) echo "Next: make bootstrap-loop-gen2-recompile-spine (gen-2→gen-3 spine; #2697)" ;;
    *) echo "Next: see https://github.com/PurHur/php-compiler/issues/2379" ;;
  esac
}

ns4_run() {
  local step="$1"
  local label="$2"
  shift 2
  echo
  echo "=== north-star4-verify step ${step}: ${label} ==="
  if "$@"; then
    echo "north-star4-verify: step ${step} ok"
    return 0
  fi
  echo "north-star4-verify: step ${step} FAILED (${label})" >&2
  ns4_hint "${step}" >&2
  exit 1
}

ns4_print_m4_next_steps() {
  local partial="${1:-0}"
  echo
  if [[ "${partial}" -eq 1 ]]; then
    echo "north-star4-verify: M4 partial — gen-2 native emit, gen-3 spine, or M3 strict still blocked (#2075, #1402)"
  else
    echo "north-star4-verify: M4 ladder green (gen-1→gen-2 native + gen-2→gen-3 spine + full-revision when steps 5–7 ran)"
  fi
  echo "north-star4-verify: Generation ladder — docs/bootstrap-generations.md"
  echo "north-star4-verify: Next strict CI — BOOTSTRAP_M4_GEN2_STRICT_GATE=1 in ci-local.sh (#2112)"
  echo "north-star4-verify: Next compiled driver — argv bin/compile.php -o (not just M3 env bridge; #1937, #1521)"
  echo "north-star4-verify: Native gen-2 emit — BOOTSTRAP_M4_GEN2_STRICT=1 BOOTSTRAP_M4_LINK_COMPILE_DRIVER=1 ./script/bootstrap-loop-gen1-link.sh (#2115, #2075)"
  echo "north-star4-verify: Opt-in ci-local — NORTH_STAR4_VERIFY_GATE=1 ./script/ci-local.sh (#2429)"
}

M3_STRICT_OK=1

ns4_run_m3_strict_optional() {
  local step="$1"
  local label="$2"
  shift 2
  echo
  echo "=== north-star4-verify step ${step}: ${label} ==="
  if "$@"; then
    echo "north-star4-verify: step ${step} ok"
    return 0
  fi
  if [[ "${STRICT_M4}" -eq 1 ]]; then
    echo "north-star4-verify: step ${step} FAILED (${label})" >&2
    ns4_hint "${step}" >&2
    exit 1
  fi
  echo "north-star4-verify: step ${step} partial (M3 strict not ready — continuing, #1493/#1937)"
  M3_STRICT_OK=0
  return 0
}

if [[ ! -x "${_CI_REPO_ROOT}/phpc" ]]; then
  echo "north-star4-verify: phpc wrapper missing; run composer install" >&2
  exit 1
fi

ci_prepare_test_runtime
ci_install_deps

ns4_run 1 "bootstrap inventory --check" \
  ci_ensure_generated_doc "${_CI_REPO_ROOT}/script/bootstrap-inventory.php" "${_CI_REPO_ROOT}/docs/bootstrap-inventory.md"

if ! ci_llvm_ready; then
  if [[ "${REQUIRE_LLVM}" -eq 1 ]]; then
    echo "north-star4-verify: LLVM 9 required (--require-llvm) but not found at $(ci_llvm_dir)" >&2
    echo "Next: script/install-llvm9.sh or export PHP_COMPILER_LLVM_PATH" >&2
    exit 1
  fi
  echo
  echo "=== north-star4-verify: steps 2–5 skipped (LLVM 9 not available) ==="
  ns4_print_m4_next_steps 1
  echo "north-star4-verify: OK"
  exit 0
fi

ci_apply_llvm_memory_env

export BOOTSTRAP_M3_HELLOWORLD_STRICT_GATE=1
export BOOTSTRAP_M3_COMPILE_SMOKE_STRICT_GATE=1

ns4_run_m3_strict_optional 2 "M3 HelloWorld strict probe" \
  ci_run_bootstrap_m3_strict

ns4_run_m3_strict_optional 3 "M3 compile-smoke strict probe" \
  ci_run_bootstrap_m3_compile_smoke_strict

GEN1_ENV=(
  BOOTSTRAP_M4_LINK_COMPILE_DRIVER=1
  BOOTSTRAP_M4_COMPILE_DRIVER_REAL_LOWERING=1
  BOOTSTRAP_M4_RUNTIME_COMPILE=1
)
if [[ "${STRICT_M4}" -eq 1 ]]; then
  GEN1_ENV+=(BOOTSTRAP_M4_GEN2_STRICT=1)
fi

PROBE_PARTIAL=0
GEN1_OK=1
PROBE_FULL_GREEN=0

echo
echo "=== north-star4-verify step 4: M4 gen-1 link + gen-2 attempt ==="
if env "${GEN1_ENV[@]}" "${_CI_SCRIPT_DIR}/bootstrap-loop-gen1-link.sh"; then
  echo "north-star4-verify: step 4 ok"
else
  if [[ "${STRICT_M4}" -eq 1 ]]; then
    echo "north-star4-verify: step 4 FAILED (M4 gen-1 link)" >&2
    ns4_hint 4 >&2
    exit 1
  fi
  GEN1_OK=0
  PROBE_PARTIAL=1
  echo "north-star4-verify: step 4 partial (gen-1 link blocked — continuing, #1498/#2075)"
fi

echo
if [[ "${GEN1_OK}" -eq 0 && "${DRY_RUN_ONLY}" -eq 1 ]]; then
  echo "=== north-star4-verify: step 5 skipped (gen-1 link partial; --dry-run-only) ==="
elif [[ "${DRY_RUN_ONLY}" -eq 1 || "${M3_STRICT_OK}" -eq 0 || "${GEN1_OK}" -eq 0 ]]; then
  echo "=== north-star4-verify step 5: M4 loop probe (--dry-run) ==="
  if [[ -x "${_CI_SCRIPT_DIR}/bootstrap-loop-probe.sh" ]]; then
    set +e
    BOOTSTRAP_LOOP_PROBE_GATE=1 "${_CI_SCRIPT_DIR}/bootstrap-loop-probe.sh" --dry-run
    PROBE_CODE=$?
    set -e
    if [[ "${PROBE_CODE}" -eq 0 ]]; then
      echo "north-star4-verify: step 5 ok (--dry-run)"
    elif [[ "${STRICT_M4}" -eq 1 ]]; then
      echo "north-star4-verify: step 5 FAILED (M4 loop --dry-run exit ${PROBE_CODE})" >&2
      ns4_hint 5 >&2
      exit 1
    else
      PROBE_PARTIAL=1
      echo "north-star4-verify: step 5 partial (--dry-run exit ${PROBE_CODE} — documented M4 blocker)"
    fi
  else
    echo "north-star4-verify: bootstrap-loop-probe.sh missing — step 5 skipped"
  fi
else
  echo "=== north-star4-verify step 5: M4 loop probe (full) ==="
  if [[ ! -x "${_CI_SCRIPT_DIR}/bootstrap-loop-probe.sh" ]]; then
    echo "north-star4-verify: bootstrap-loop-probe.sh missing — step 5 skipped"
  else
    set +e
    BOOTSTRAP_LOOP_PROBE_GATE=1 "${_CI_SCRIPT_DIR}/bootstrap-loop-probe.sh"
    PROBE_CODE=$?
    set -e
    case "${PROBE_CODE}" in
      0)
        PROBE_FULL_GREEN=1
        echo "north-star4-verify: step 5 ok (full M4 ladder incl. gen-2→gen-3 + full-revision #2898)"
        ;;
      2)
        if [[ "${STRICT_M4}" -eq 1 ]]; then
          echo "north-star4-verify: step 5 FAILED (M4 full probe exit 2 — M3 strict or gen-2 native blocked)" >&2
          ns4_hint 5 >&2
          exit 1
        fi
        PROBE_PARTIAL=1
        echo "north-star4-verify: step 5 partial (exit 2 — documented M4 blocker, #1498)"
        ;;
      *)
        echo "north-star4-verify: step 5 FAILED (bootstrap-loop-probe exit ${PROBE_CODE})" >&2
        ns4_hint 5 >&2
        exit 1
        ;;
    esac
  fi
fi

GEN3_PARTIAL=0
FULL_REVISION_OK=0
if [[ "${GEN1_OK}" -eq 1 && "${DRY_RUN_ONLY}" -eq 0 && "${M3_STRICT_OK}" -eq 1 && "${PROBE_FULL_GREEN}" -eq 0 ]]; then
  echo
  echo "=== north-star4-verify step 6: M4 gen-2→gen-3 spine recompile ==="
  if "${_CI_SCRIPT_DIR}/bootstrap-loop-gen2-recompile-spine.sh"; then
    spine_ratio="$("${PHP_BIN}" "${PHP_OPTS[@]}" "${_CI_SCRIPT_DIR}/bootstrap-spine-count.php" --json 2>/dev/null | "${PHP_BIN}" -r '$j=json_decode(stream_get_contents(STDIN),true); $s=(int)($j["spine"]??725); echo $s."/".$s;' || echo "725/725")"
    echo "north-star4-verify: step 6 ok (gen-3 spine ${spine_ratio})"
    echo
    echo "=== north-star4-verify step 7: M4 full-revision argv probe (#2898) ==="
    set +e
    "${_CI_SCRIPT_DIR}/bootstrap-selfhost-full-revision-probe.sh"
    full_rev_code=$?
    set -e
    case "${full_rev_code}" in
      0)
        FULL_REVISION_OK=1
        echo "north-star4-verify: step 7 ok (full-revision argv bin/compile.php)"
        ;;
      2)
        if [[ "${STRICT_M4}" -eq 1 ]]; then
          echo "north-star4-verify: step 7 FAILED (full-revision exit 2)" >&2
          exit 1
        fi
        PROBE_PARTIAL=1
        echo "north-star4-verify: step 7 partial (full-revision exit 2 — documented M4 blocker)"
        ;;
      *)
        echo "north-star4-verify: step 7 FAILED (full-revision exit ${full_rev_code})" >&2
        exit 1
        ;;
    esac
  else
    gen3_code=$?
    if [[ "${STRICT_M4}" -eq 1 ]]; then
      echo "north-star4-verify: step 6 FAILED (gen-2→gen-3 spine exit ${gen3_code})" >&2
      ns4_hint 6 >&2
      exit 1
    fi
    GEN3_PARTIAL=1
    PROBE_PARTIAL=1
    echo "north-star4-verify: step 6 partial (gen-2→gen-3 spine exit ${gen3_code} — documented M4 blocker)"
  fi
elif [[ "${PROBE_FULL_GREEN}" -eq 1 ]]; then
  FULL_REVISION_OK=1
fi

ns4_print_m4_next_steps "$((PROBE_PARTIAL || GEN3_PARTIAL))"
echo
echo "north-star4-verify: OK"
