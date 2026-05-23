#!/usr/bin/env bash
# Ordered #764 AOT fixture ladder — smallest failing step first (issues/879).
#
#   ./script/miniwebapp-aot-bisect.sh
#   ./script/miniwebapp-aot-bisect.sh --list
#   ./script/miniwebapp-aot-bisect.sh --from nested_include_two_tier
#   MINIWEBAPP_AOT_BISECT_INCLUDE_APP=1 ./script/miniwebapp-aot-bisect.sh
#
# Requires LLVM 9 and vendor/ (composer install). Local/Docker only — no GHA.
set -euo pipefail

cd "$(dirname "$0")/.."
ROOT="$PWD"
REPO_URL="https://github.com/PurHur/php-compiler"
MINIWEBAPP="${ROOT}/examples/003-MiniWebApp"
AOT_TEST="${ROOT}/test/aot/AotTest.php"

# shellcheck source=ci-common.sh
source "${ROOT}/script/ci-common.sh"

# step_id|phpunit --filter|issue
readonly -a BISECT_STEPS=(
  'isset_object_property_array|isset_object_property_array|848'
  'require_return_config|require_return_config|806'
  'nested_include_two_tier|nested_include_two_tier|878'
  'deploy_path_layout_nested|deploy_path_layout_nested|878'
  'miniwebapp_render_home|miniwebapp_render_home|867'
  'layout_script_base|layout_script_base|866'
  'coalesce_then_inherited_local|coalesce_then_inherited_local|866'
  'coalesce_then_htmlspecialchars|coalesce_then_htmlspecialchars|866'
  'coalesce_scriptbase_htmlspecialchars|coalesce_scriptbase_htmlspecialchars|764'
  'coalesce_then_nested_include|coalesce_then_nested_include|784'
  'layout_title_branch|layout_title_branch|784'
  'method_include_void_array_property|method_include_void_array_property|846'
)

FROM_STEP=""
LIST_ONLY=0
INCLUDE_APP="${MINIWEBAPP_AOT_BISECT_INCLUDE_APP:-0}"

usage() {
  cat <<'EOF'
Usage: script/miniwebapp-aot-bisect.sh [--list] [--from STEP]

Runs MiniWebApp-related AOT PHPT fixtures smallest → largest; exits non-zero
on the first failure and prints the tracking GitHub issue.

Options:
  --list              Print configured steps and exit 0
  --from STEP         Skip steps before STEP (same id as --list)

Environment:
  MINIWEBAPP_AOT_BISECT_INCLUDE_APP=1   After PHPT ladder, probe 003 CLI execute (#764, #747)
  PHP_COMPILER_LLVM_PATH                LLVM 9 install dir (see script/install-llvm9.sh)

See: examples/003-MiniWebApp/README.md, issues/879, ROADMAP issues/764
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --list) LIST_ONLY=1; shift ;;
    --from)
      [[ $# -ge 2 ]] || { echo "miniwebapp-aot-bisect: --from requires STEP" >&2; exit 1; }
      FROM_STEP="$2"
      shift 2
      ;;
    -h|--help) usage; exit 0 ;;
    *) echo "miniwebapp-aot-bisect: unknown argument: $1" >&2; usage; exit 1 ;;
  esac
done

if [[ ! -f "${ROOT}/vendor/bin/phpunit" ]]; then
  echo "miniwebapp-aot-bisect: vendor/bin/phpunit missing — run composer install" >&2
  exit 1
fi

if ! ci_llvm_ready; then
  echo "miniwebapp-aot-bisect: skipped (LLVM 9 not available; script/install-llvm9.sh or .llvm/)" >&2
  exit 0
fi

ci_cd_repo
ci_prepare_test_runtime
ci_apply_llvm_memory_env

list_steps() {
  local row step_id filter issue
  echo "MiniWebApp AOT bisect ladder (issues/764, issues/879)"
  echo "  Repo: ${REPO_URL}"
  echo
  for row in "${BISECT_STEPS[@]}"; do
    IFS='|' read -r step_id filter issue <<<"$row"
    echo "  ${step_id}  phpunit --filter ${filter}  ${REPO_URL}/issues/${issue}"
  done
  if [[ "${INCLUDE_APP}" == "1" ]]; then
    echo "  003-cli-execute  examples/003-MiniWebApp/.phpc/bin/app  ${REPO_URL}/issues/764"
  else
    echo "  (optional) MINIWEBAPP_AOT_BISECT_INCLUDE_APP=1 — full 003 CLI byte probe (#764)"
  fi
}

if [[ "${LIST_ONLY}" -eq 1 ]]; then
  list_steps
  exit 0
fi

step_known() {
  local want="$1" row step_id
  for row in "${BISECT_STEPS[@]}"; do
    IFS='|' read -r step_id _ _ <<<"$row"
    if [[ "${step_id}" == "${want}" ]]; then
      return 0
    fi
  done
  return 1
}

if [[ -n "${FROM_STEP}" ]] && ! step_known "${FROM_STEP}"; then
  echo "miniwebapp-aot-bisect: unknown --from step: ${FROM_STEP}" >&2
  echo "  Use --list for step ids." >&2
  exit 1
fi

run_phpt_step() {
  local step_id="$1" filter="$2" issue="$3"
  echo "miniwebapp-aot-bisect: ${step_id} (phpunit --filter ${filter})..."
  set +e
  "${PHP_BIN}" "${PHP_OPTS[@]}" vendor/bin/phpunit \
    --filter "${filter}" \
    "${AOT_TEST}" 2>&1 | sed 's/^/  /'
  local code=${PIPESTATUS[0]}
  set -e
  if [[ "${code}" -ne 0 ]]; then
    echo "miniwebapp-aot-bisect: FAILED at ${step_id} — ${REPO_URL}/issues/${issue} (#764)" >&2
    return 1
  fi
  echo "miniwebapp-aot-bisect: ${step_id}: ok"
  return 0
}

run_003_cli_probe() {
  if [[ ! -d "${MINIWEBAPP}/public" ]]; then
    echo "miniwebapp-aot-bisect: 003-cli-execute: skip (tree missing #246)" >&2
    return 0
  fi
  local binary="${MINIWEBAPP}/.phpc/bin/app"
  echo "miniwebapp-aot-bisect: 003-cli-execute: phpc build --project..."
  set +e
  "${ROOT}/phpc" build --project "${MINIWEBAPP}" >/dev/null 2>&1
  local build_code=$?
  set -e
  if [[ "${build_code}" -ne 0 ]]; then
    echo "miniwebapp-aot-bisect: FAILED at 003-cli-execute (link) — ${REPO_URL}/issues/764" >&2
    return 1
  fi
  if [[ ! -x "${binary}" ]]; then
    echo "miniwebapp-aot-bisect: FAILED at 003-cli-execute (missing ${binary}) — ${REPO_URL}/issues/764" >&2
    return 1
  fi
  local stderr_file stdout
  stderr_file="$(mktemp)"
  set +e
  stdout="$(env \
    QUERY_STRING='route=home' \
    SCRIPT_NAME='/index.php' \
    REQUEST_URI='/index.php?route=home' \
    REQUEST_METHOD='GET' \
    "${binary}" 2>"${stderr_file}")"
  local run_code=$?
  set -e
  local stderr
  stderr="$(cat "${stderr_file}" 2>/dev/null || true)"
  rm -f "${stderr_file}"
  if [[ "${run_code}" -ne 0 ]]; then
    echo "miniwebapp-aot-bisect: FAILED at 003-cli-execute (exit ${run_code}) — ${REPO_URL}/issues/764" >&2
    [[ -n "${stderr}" ]] && echo "${stderr}" | sed 's/^/  /' >&2
    return 1
  fi
  if [[ "${stdout}" != *'MiniWebApp'* ]]; then
    echo "miniwebapp-aot-bisect: FAILED at 003-cli-execute (empty or wrong stdout) — ${REPO_URL}/issues/764" >&2
    echo "--- stdout ---" >&2
    echo "${stdout}" >&2
    echo "--- end ---" >&2
    return 1
  fi
  echo "miniwebapp-aot-bisect: 003-cli-execute: ok"
  return 0
}

started=0
if [[ -z "${FROM_STEP}" ]]; then
  started=1
fi

for row in "${BISECT_STEPS[@]}"; do
  IFS='|' read -r step_id filter issue <<<"$row"
  if [[ "${started}" -eq 0 ]]; then
    if [[ "${step_id}" == "${FROM_STEP}" ]]; then
      started=1
    else
      continue
    fi
  fi
  if ! run_phpt_step "${step_id}" "${filter}" "${issue}"; then
    exit 1
  fi
done

if [[ "${started}" -eq 0 ]]; then
  echo "miniwebapp-aot-bisect: --from ${FROM_STEP} did not match any step" >&2
  exit 1
fi

if [[ "${INCLUDE_APP}" == "1" ]]; then
  if ! run_003_cli_probe; then
    exit 1
  fi
fi

echo "miniwebapp-aot-bisect: all steps passed"
