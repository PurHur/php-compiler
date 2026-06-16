#!/usr/bin/env bash
# User-release readiness presenter (#8737, #8739, #78).
#
# Quick mode (<5 min Docker): inventory, spine sync, docs sync, examples 000 smoke.
# Full mode: quick bundle + capability sync, CHANGELOG stub, examples AOT/web smoke.
#
# Usage:
#   ./script/release-readiness.sh [--full] [--json]
#
# Docker:
#   ./script/docker-exec.sh -- bash -lc './script/release-readiness.sh --json'
#   ./script/docker-exec.sh -- bash -lc './script/release-readiness.sh --full --json'
#
# Optional heavier gate: RELEASE_READINESS_CI_FAST=1 runs ./script/ci-fast.sh after quick bundle.
set -euo pipefail

# shellcheck source=ci-common.sh
source "$(dirname "$0")/ci-common.sh"

ci_cd_repo

FULL=0
JSON=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --full) FULL=1; shift ;;
    --json) JSON=1; shift ;;
    -h|--help)
      cat <<'EOF'
Usage: script/release-readiness.sh [--full] [--json]

Aggregates user-release gates for v1.1.0 review (#8737). Local/Docker only.

Quick (default):
  bootstrap-inventory --check, spine coverage sync, root README sync,
  docs sync (examples README + development-status), examples/000 VM smoke

Full (--full):
  quick bundle + development-status sync, capability-matrix --check,
  CHANGELOG stub, examples AOT/web smoke

Options:
  --json   machine output: {"user_release_ready":"yes"|"no","gates":[...]}
  --full   include user-release gates (AOT/web smoke, capability, CHANGELOG)

Environment:
  RELEASE_READINESS_SKIP_EXAMPLES=1   test-only: skip examples-000-smoke (CiScriptsTest)
  RELEASE_READINESS_CI_FAST=1         optional: run ./script/ci-fast.sh after quick bundle
  PHP_COMPILER_SKIP_SERVE_TESTS       honored by examples-web-smoke.sh (full mode)
EOF
      exit 0
      ;;
    *) echo "release-readiness: unknown argument: $1" >&2; exit 1 ;;
  esac
done

GATE_JSONL="${TMPDIR:-/tmp}/release-readiness-gates.$$.jsonl"
: >"$GATE_JSONL"
trap 'rm -f "$GATE_JSONL"' EXIT

USER_RELEASE_READY=yes

rr_record_gate() {
  local name="$1" status="$2" msg="$3"
  if [[ "$status" == "fail" ]]; then
    USER_RELEASE_READY=no
  fi
  NAME="$name" STATUS="$status" MSG="$msg" GATE_FILE="$GATE_JSONL" "$PHP_BIN" -r '
    $line = json_encode([
      "name" => getenv("NAME"),
      "status" => getenv("STATUS"),
      "message" => getenv("MSG"),
    ], JSON_UNESCAPED_SLASHES);
    file_put_contents(getenv("GATE_FILE"), $line . "\n", FILE_APPEND);
  '
}

rr_run_gate() {
  local name="$1"
  shift
  if [[ "$JSON" == "0" ]]; then
    echo
    echo "=== release-readiness: ${name} ==="
  fi
  local out=""
  local code=0
  set +e
  out="$("$@" 2>&1)"
  code=$?
  set -e
  if [[ "$code" -eq 0 ]]; then
    rr_record_gate "$name" "pass" "ok"
    if [[ "$JSON" == "0" && -n "$out" ]]; then
      echo "$out" | tail -3
    elif [[ "$JSON" == "1" && -n "$out" ]]; then
      echo "$out" | tail -3 >&2
    fi
    return 0
  fi
  rr_record_gate "$name" "fail" "exit ${code}"
  if [[ -n "$out" ]]; then
    echo "$out" >&2
  fi
  return 0
}

rr_check_changelog_stub() {
  local changelog="${_CI_REPO_ROOT}/CHANGELOG.md"
  if [[ ! -f "$changelog" ]]; then
    echo "release-readiness: missing CHANGELOG.md (draft required for v1.1.0 — #8739)" >&2
    return 1
  fi
  if ! grep -qE '^## \[?(Unreleased|1\.1\.0|v1\.1\.0)\]?' "$changelog"; then
    echo "release-readiness: CHANGELOG.md must contain Unreleased or 1.1.0 section (#8739)" >&2
    return 1
  fi
  return 0
}

rr_run_examples_000_smoke() {
  ci_install_deps
  ci_run_phpunit test/unit/ExamplesCompileTest.php --filter '000-HelloWorld'
}

rr_run_docs_sync() {
  ci_run_examples_readme_sync_check
}

rr_run_ci_fast_optional() {
  ci_install_deps
  env \
    NORTH_STAR5_VERIFY_FAST_GATE=0 \
    NORTH_STAR2_VERIFY_GATE=0 \
    NORTH_STAR3_VERIFY_GATE=0 \
    BOOTSTRAP_TEST_SUBSET_GATE=0 \
    CI_FAST_BOOTSTRAP=0 \
    DEVELOPMENT_STATUS_SYNC_GATE=0 \
    "${_CI_SCRIPT_DIR}/ci-fast.sh"
}

rr_emit_json() {
  READY="$USER_RELEASE_READY" GATE_FILE="$GATE_JSONL" "$PHP_BIN" -r '
    $ready = getenv("READY") === "yes" ? "yes" : "no";
    $gates = [];
    foreach (file(getenv("GATE_FILE"), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
      $gates[] = json_decode($line, true);
    }
    echo json_encode(["user_release_ready" => $ready, "gates" => $gates], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
  '
}

ci_prepare_test_runtime

rr_run_gate bootstrap-inventory \
  "$PHP_BIN" "${PHP_OPTS[@]}" script/bootstrap-inventory.php --check

rr_run_gate spine-coverage-sync \
  "$PHP_BIN" "${PHP_OPTS[@]}" script/check-selfhost-spine-coverage-sync.php

rr_run_gate root-readme-sync \
  env ROOT_README_SYNC_GATE=1 \
    ROOT_README_006_SYNC_GATE=1 \
    ROOT_README_007_SYNC_GATE=1 \
    ROOT_README_008_SYNC_GATE=1 \
    ROOT_README_009_SYNC_GATE=1 \
    "$PHP_BIN" "${PHP_OPTS[@]}" script/check-root-readme-sync.php

if [[ "$JSON" == "0" ]]; then
  echo
  echo "=== release-readiness: docs-sync ==="
fi
if rr_run_docs_sync; then
  rr_record_gate docs-sync pass ok
else
  rr_record_gate docs-sync fail "docs sync failed"
fi

if [[ "$JSON" == "0" ]]; then
  echo
  echo "=== release-readiness: examples-000-smoke ==="
fi
if [[ "${RELEASE_READINESS_SKIP_EXAMPLES:-0}" == "1" ]]; then
  rr_record_gate examples-000-smoke skip "RELEASE_READINESS_SKIP_EXAMPLES=1"
elif rr_run_examples_000_smoke; then
  rr_record_gate examples-000-smoke pass ok
else
  rr_record_gate examples-000-smoke fail "examples/000 VM smoke failed"
fi

if [[ "${RELEASE_READINESS_CI_FAST:-0}" == "1" ]]; then
  if rr_run_ci_fast_optional; then
    rr_record_gate ci-fast pass ok
  else
    rr_record_gate ci-fast fail "ci-fast failed"
  fi
fi

if [[ "$FULL" == "1" ]]; then
  if [[ "$JSON" == "0" ]]; then
    echo
    echo "=== release-readiness: development-status-sync ==="
  fi
  if ci_run_development_status_sync_check; then
    rr_record_gate development-status-sync pass ok
  else
    rr_record_gate development-status-sync fail "development-status sync failed"
  fi

  rr_run_gate capability-matrix \
    "$PHP_BIN" "${PHP_OPTS[@]}" script/capability-matrix.php --check

  if [[ "$JSON" == "0" ]]; then
    echo
    echo "=== release-readiness: changelog-stub ==="
  fi
  if rr_check_changelog_stub; then
    rr_record_gate changelog-stub pass ok
  else
    rr_record_gate changelog-stub fail "CHANGELOG stub check failed"
  fi

  rr_run_gate examples-aot-smoke "${_CI_SCRIPT_DIR}/examples-aot-smoke.sh"

  rr_run_gate examples-web-smoke "${_CI_SCRIPT_DIR}/examples-web-smoke.sh"
fi

if [[ "$JSON" == "1" ]]; then
  rr_emit_json
else
  echo
  echo "release-readiness: user_release_ready=${USER_RELEASE_READY}"
fi

if [[ "$USER_RELEASE_READY" == "yes" ]]; then
  exit 0
fi
exit 1
