#!/usr/bin/env bash
# Release readiness presenter — user-release gate bundle (#8737, #8739, #78).
#
# Quick mode (default, target <5 min in Docker):
#   bootstrap inventory + spine coverage sync + root README sync
#   Optional ci-fast subset: RELEASE_READINESS_CI_FAST=1
#
# Full mode (--full):
#   quick bundle + capability sync + examples AOT/web smoke + CHANGELOG stub
#
# Usage:
#   ./script/release-readiness.sh [--full] [--json] [--dry-run]
#   ./script/docker-exec.sh -- bash -lc './script/release-readiness.sh --json'
#
# Machine output (--json):
#   {"user_release_ready":"yes"|"no","mode":"quick"|"full","gates":[...]}
set -euo pipefail

# shellcheck source=ci-common.sh
source "$(dirname "$0")/ci-common.sh"

ci_cd_repo
ci_prepare_test_runtime

FULL_MODE=0
JSON_OUT=0
DRY_RUN=0

usage() {
  cat <<'EOF'
Usage: script/release-readiness.sh [--full] [--json] [--dry-run]

Quick mode (default): inventory --check, spine coverage sync, root README sync.
  RELEASE_READINESS_CI_FAST=1   also run ci-fast inventory/doc sync subset (~minutes)

Full mode (--full): quick + capability-matrix --check, examples-aot-smoke,
  examples-web-smoke, CHANGELOG v1.1.0 stub check.

Options:
  --full     user-release gate bundle (LLVM + HTTP smokes when available)
  --json     print {"user_release_ready","mode","gates"} on stdout; human log on stderr
  --dry-run  list gates without executing

Docker:
  ./script/docker-exec.sh -- bash -lc './script/release-readiness.sh --json'
  ./script/docker-exec.sh -- bash -lc './script/release-readiness.sh --full --json'

See docs/local-ci-matrix.md (#8737).
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --full) FULL_MODE=1; shift ;;
    --json) JSON_OUT=1; shift ;;
    --dry-run) DRY_RUN=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "release-readiness: unknown argument: $1" >&2; usage >&2; exit 1 ;;
  esac
done

MODE=quick
if [[ "${FULL_MODE}" -eq 1 ]]; then
  MODE=full
fi

GATE_NAMES=()
GATE_STATUSES=()
GATE_MESSAGES=()

log() {
  if [[ "${JSON_OUT}" -eq 1 ]]; then
    echo "release-readiness: $*" >&2
  else
    echo "release-readiness: $*"
  fi
}

if [[ ! -f "${_CI_REPO_ROOT}/vendor/autoload.php" ]]; then
  log "vendor/ missing — running composer install + apply-patches (#9224)"
  ci_install_deps
fi

record_gate() {
  GATE_NAMES+=("$1")
  GATE_STATUSES+=("$2")
  GATE_MESSAGES+=("${3:-}")
}

run_gate() {
  local name="$1"
  local label="$2"
  shift 2
  if [[ "${DRY_RUN}" -eq 1 ]]; then
    log "dry-run: would run ${label}"
    record_gate "${name}" skip "dry-run"
    return 0
  fi
  log "gate ${label}..."
  local err_file
  err_file="$(mktemp)"
  set +e
  "$@" >"${err_file}" 2>&1
  local rc=$?
  set -e
  local tail_msg=""
  if [[ -s "${err_file}" ]]; then
    tail_msg="$(tail -n 3 "${err_file}" | tr '\n' ' ' | sed 's/  */ /g' | head -c 240)"
  fi
  rm -f "${err_file}"
  if [[ "${rc}" -eq 0 ]]; then
    record_gate "${name}" ok "${tail_msg}"
    log "  ok: ${label}"
    return 0
  fi
  record_gate "${name}" fail "${tail_msg}"
  log "  FAIL: ${label} (exit ${rc})" >&2
  if [[ -n "${tail_msg}" ]]; then
    log "  ${tail_msg}" >&2
  fi
  return 1
}

run_gate_bootstrap_inventory() {
  local name="bootstrap-inventory"
  local label="bootstrap-inventory --check"
  if [[ "${DRY_RUN}" -eq 1 ]]; then
    log "dry-run: would run ${label}"
    record_gate "${name}" skip "dry-run"
    return 0
  fi
  if [[ ! -f "${_CI_REPO_ROOT}/vendor/autoload.php" ]]; then
    log "vendor/ missing — running composer install + apply-patches (#10531)"
    ci_install_deps
  fi
  log "gate ${label}..."
  local err_file
  err_file="$(mktemp)"
  set +e
  # Match north-star5 step 1: auto-regenerate when stale (#765) — bare --check alone
  # false-fails while north-star5-verify-fast passes in the same bundle (#10531).
  ci_ensure_generated_doc "${_CI_REPO_ROOT}/script/bootstrap-inventory.php" "${_CI_REPO_ROOT}/docs/bootstrap-inventory.md" >"${err_file}" 2>&1
  local ensure_rc=$?
  "$PHP_BIN" "${PHP_OPTS[@]}" script/bootstrap-inventory.php --check >>"${err_file}" 2>&1
  local rc=$?
  set -e
  local body=""
  if [[ -s "${err_file}" ]]; then
    body="$(cat "${err_file}")"
  fi
  rm -f "${err_file}"
  if [[ "${ensure_rc}" -eq 0 && "${rc}" -eq 0 ]] && grep -qE '^OK [0-9]+/[0-9]+$' <<<"${body}"; then
    record_gate "${name}" ok "$(grep -E '^OK [0-9]+/[0-9]+$' <<<"${body}" | tail -n 1 | tr -d '\n')"
    log "  ok: ${label}"
    return 0
  fi
  local tail_msg=""
  if [[ -n "${body}" ]]; then
    tail_msg="$(printf '%s' "${body}" | tail -n 5 | tr '\n' ' ' | sed 's/  */ /g' | head -c 240)"
  fi
  if grep -qi 'vendor/ absent' <<<"${body}"; then
    tail_msg="vendor/ missing; run composer install before --check (#10531). ${tail_msg}"
  elif grep -qi 'Stale ' <<<"${body}"; then
    tail_msg="stale docs/bootstrap-inventory.md — run: php script/bootstrap-inventory.php (#10368). ${tail_msg}"
  fi
  record_gate "${name}" fail "${tail_msg}"
  log "  FAIL: ${label} (ensure=${ensure_rc} check=${rc})" >&2
  if [[ -n "${body}" ]]; then
    printf '%s\n' "${body}" | tail -n 8 >&2
  fi
  return 1
}

run_gate_allow_skip() {
  local name="$1"
  local label="$2"
  shift 2
  if [[ "${DRY_RUN}" -eq 1 ]]; then
    log "dry-run: would run ${label}"
    record_gate "${name}" skip "dry-run"
    return 0
  fi
  log "gate ${label}..."
  local err_file
  err_file="$(mktemp)"
  set +e
  "$@" >"${err_file}" 2>&1
  local rc=$?
  set -e
  local body=""
  if [[ -s "${err_file}" ]]; then
    body="$(cat "${err_file}")"
  fi
  rm -f "${err_file}"
  if [[ "${rc}" -eq 0 ]]; then
    # examples-*-smoke print opt-in slice skips (SESSIONS_WEB_AOT_SMOKE_GATE=0, etc.)
    # but still end with "examples-*-smoke: ok" — treat that as green (#8739, #78).
    if grep -qE '^(examples-aot-smoke|examples-web-smoke): ok$' <<<"${body}"; then
      record_gate "${name}" ok ""
      log "  ok: ${label}"
      return 0
    fi
    if grep -qiE '^(examples-aot-smoke|examples-web-smoke): skipped \(' <<<"${body}"; then
      record_gate "${name}" skip "$(printf '%s' "${body}" | tail -n 1 | tr -d '\n' | head -c 240)"
      log "  skip: ${label}"
      return 0
    fi
    if grep -qiE 'skipped \(|: skip' <<<"${body}"; then
      record_gate "${name}" skip "$(printf '%s' "${body}" | tail -n 1 | tr -d '\n' | head -c 240)"
      log "  skip: ${label}"
      return 0
    fi
    record_gate "${name}" ok ""
    log "  ok: ${label}"
    return 0
  fi
  record_gate "${name}" fail "$(printf '%s' "${body}" | tail -n 3 | tr '\n' ' ' | head -c 240)"
  log "  FAIL: ${label} (exit ${rc})" >&2
  return 1
}

release_readiness_check_changelog_stub() {
  local changelog="${_CI_REPO_ROOT}/CHANGELOG.md"
  if [[ ! -f "${changelog}" ]]; then
    echo "release-readiness: missing CHANGELOG.md — add ## v1.1.0 stub (#8739)" >&2
    return 1
  fi
  if ! grep -qE '^## v1\.1\.0\b' "${changelog}"; then
    echo "release-readiness: CHANGELOG.md missing ## v1.1.0 section (#8739)" >&2
    return 1
  fi
  echo "release-readiness: CHANGELOG v1.1.0 stub OK"
  return 0
}

release_readiness_ci_fast_subset() {
  ci_run_wave3_roadmap_sync_check
  ci_run_examples_readme_sync_check
  ci_run_development_status_sync_check
  ci_run_selfhost_spine_count_sync_check
  "$PHP_BIN" "${PHP_OPTS[@]}" script/capability-matrix.php --check
}

FAILED=0

# --- Quick bundle ---
run_gate_bootstrap_inventory || FAILED=1

run_gate spine-coverage "check-selfhost-spine-coverage-sync" \
  "$PHP_BIN" "${PHP_OPTS[@]}" script/check-selfhost-spine-coverage-sync.php \
  || FAILED=1

run_gate root-readme-sync "check-root-readme-sync (ROOT_README_SYNC_GATE=1)" \
  env ROOT_README_SYNC_GATE=1 "$PHP_BIN" "${PHP_OPTS[@]}" script/check-root-readme-sync.php \
  || FAILED=1

run_gate_allow_skip north-star5-fast "north-star5-verify --fast (M5 daily gate)" \
  make -C "${_CI_REPO_ROOT}" north-star5-verify-fast \
  || FAILED=1

run_gate_allow_skip vm-driver-probe "bootstrap-selfhost-vm-driver-execute-probe" \
  make -C "${_CI_REPO_ROOT}" bootstrap-selfhost-vm-driver-execute-probe \
  || FAILED=1

if [[ "${RELEASE_READINESS_CI_FAST:-0}" == "1" ]]; then
  run_gate ci-fast-subset "ci-fast inventory/doc subset (RELEASE_READINESS_CI_FAST=1)" \
    release_readiness_ci_fast_subset \
    || FAILED=1
fi

# --- Full bundle ---
if [[ "${FULL_MODE}" -eq 1 ]]; then
  run_gate capability-matrix "capability-matrix --check" \
    "$PHP_BIN" "${PHP_OPTS[@]}" script/capability-matrix.php --check \
    || FAILED=1

  run_gate_allow_skip examples-aot-smoke "examples-aot-smoke.sh (000–009)" \
    "${_CI_SCRIPT_DIR}/examples-aot-smoke.sh" \
    || FAILED=1

  run_gate_allow_skip examples-web-smoke "examples-web-smoke.sh" \
    "${_CI_SCRIPT_DIR}/examples-web-smoke.sh" \
    || FAILED=1

  run_gate changelog-stub "CHANGELOG v1.1.0 stub" \
    release_readiness_check_changelog_stub \
    || FAILED=1
fi

USER_RELEASE_READY=no
if [[ "${FAILED}" -eq 0 ]]; then
  USER_RELEASE_READY=yes
fi

# Full user release requires LLVM/HTTP smokes to run green, not skip.
if [[ "${FULL_MODE}" -eq 1 && "${USER_RELEASE_READY}" == "yes" ]]; then
  for i in "${!GATE_NAMES[@]}"; do
    case "${GATE_NAMES[$i]}" in
      examples-aot-smoke|examples-web-smoke)
        if [[ "${GATE_STATUSES[$i]}" != "ok" ]]; then
          USER_RELEASE_READY=no
          break
        fi
        ;;
    esac
  done
fi

if [[ "${JSON_OUT}" -eq 1 ]]; then
  export _RR_MODE="${MODE}"
  export _RR_READY="${USER_RELEASE_READY}"
  export _RR_GATE_COUNT="${#GATE_NAMES[@]}"
  for i in "${!GATE_NAMES[@]}"; do
    export "_RR_GATE_NAME_${i}=${GATE_NAMES[$i]}"
    export "_RR_GATE_STATUS_${i}=${GATE_STATUSES[$i]}"
    export "_RR_GATE_MESSAGE_${i}=${GATE_MESSAGES[$i]}"
  done
  "$PHP_BIN" "${PHP_OPTS[@]}" "${_CI_SCRIPT_DIR}/release-readiness-json-emit.php"
else
  log "mode=${MODE} user_release_ready=${USER_RELEASE_READY}"
fi

if [[ "${FAILED}" -ne 0 ]]; then
  exit 1
fi

exit 0
