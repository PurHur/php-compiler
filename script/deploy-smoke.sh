#!/usr/bin/env bash
# phpc deploy + PHPC_DEPLOY_ROOT CGI smoke (issue #718).
#
# Builds a shipped web example, runs phpc deploy, and executes bin/app under
# PHPC_DEPLOY_ROOT with CGI-style env (no HTTP server). Skips with exit 0 when
# LLVM 9 is missing. 003-MiniWebApp stays skipped until AOT execute #764 (PHPUnit: #612).
#
# Usage:
#   ./script/deploy-smoke.sh
#   ./script/deploy-smoke.sh --example 001
#   ./script/deploy-smoke.sh --example 002
#
# Docker:
#   docker run --rm -v "$(pwd):/compiler" -w /compiler php-compiler:22.04-dev make deploy-smoke
set -euo pipefail

cd "$(dirname "$0")/.."
ROOT="$PWD"
PHPC="${ROOT}/phpc"
SMOKE_ROOT="${ROOT}/.phpc/smoke/deploy"
MINIWEBAPP="${ROOT}/examples/003-MiniWebApp"
EXAMPLE="002"

usage() {
  cat <<'EOF' >&2
Usage: script/deploy-smoke.sh [--example 001|002]

  001  examples/001-SimpleWeb (QUERY_STRING=name=…)
  002  examples/002-StaticWeb (default; static HTML)

003-MiniWebApp is skipped until native AOT execute (#764); see #612.
EOF
  exit 1
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --example)
      [[ $# -ge 2 ]] || usage
      EXAMPLE="$2"
      shift 2
      ;;
    -h|--help) usage ;;
    *) echo "deploy-smoke: unknown argument: $1" >&2; usage ;;
  esac
done

resolve_llvm_dir() {
  if [[ -n "${PHP_COMPILER_LLVM_PATH:-}" ]]; then
    if [[ -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
      echo "${PHP_COMPILER_LLVM_PATH}"
      return 0
    fi
    return 1
  fi
  if [[ -f "${ROOT}/.llvm/libLLVM-9.so.1" ]]; then
    echo "${ROOT}/.llvm"
    return 0
  fi
  if [[ -f /opt/llvm9/libLLVM-9.so.1 ]]; then
    echo /opt/llvm9
    return 0
  fi
  return 1
}

LLVM_DIR=""
if ! LLVM_DIR="$(resolve_llvm_dir)"; then
  hint="${PHP_COMPILER_LLVM_PATH:-${ROOT}/.llvm}"
  echo "deploy-smoke: skipped (LLVM 9 not available at ${hint})"
  exit 0
fi
export PHP_COMPILER_LLVM_PATH="$LLVM_DIR"

# shellcheck source=php-env.sh
source "${ROOT}/script/php-env.sh"
export PHP_COMPILER_LLVM_PATH="$LLVM_DIR"

if [[ ! -x "$PHPC" ]]; then
  echo "deploy-smoke: phpc wrapper missing or not executable: ${PHPC}" >&2
  exit 1
fi

assert_needle() {
  local label="$1"
  local output="$2"
  shift 2
  local needle
  for needle in "$@"; do
    if [[ "$output" != *"$needle"* ]]; then
      echo "deploy-smoke: ${label}: output missing needle: ${needle}" >&2
      echo "--- output ---" >&2
      echo "$output" >&2
      echo "--- end ---" >&2
      exit 1
    fi
  done
}

run_deployed_app() {
  local label="$1"
  local dist="$2"
  shift 2
  local stderr_file stdout stderr
  stderr_file="$(mktemp "${SMOKE_ROOT}/run.XXXXXX")"
  if (("$#" > 0)); then
    stdout="$(env PHPC_DEPLOY_ROOT="$dist" "$@" "${dist}/bin/app" 2>"$stderr_file")"
  else
    stdout="$(env PHPC_DEPLOY_ROOT="$dist" "${dist}/bin/app" 2>"$stderr_file")"
  fi
  local exit_code=$?
  stderr="$(cat "$stderr_file" 2>/dev/null || true)"
  rm -f "$stderr_file"
  if [[ "$exit_code" -ne 0 ]]; then
    echo "deploy-smoke: ${label}: bin/app exited ${exit_code}" >&2
    [[ -n "$stderr" ]] && echo "$stderr" >&2
    exit 1
  fi
  if [[ -n "$stderr" ]]; then
    echo "deploy-smoke: ${label}: stderr: ${stderr}" >&2
    exit 1
  fi
  printf '%s' "$stdout"
}

smoke_deploy_example() {
  local id="$1"
  local project_rel="$2"
  local label="$3"
  local project="${ROOT}/${project_rel}"
  local dist="${SMOKE_ROOT}/${id}"
  local readme="${dist}/README.deploy"

  if [[ ! -d "$project" ]]; then
    echo "deploy-smoke: ${label}: skip (tree missing)" >&2
    return 0
  fi

  rm -rf "$dist"
  mkdir -p "$SMOKE_ROOT"

  echo "deploy-smoke: ${label}: phpc build --project"
  "$PHPC" build --project "$project"

  echo "deploy-smoke: ${label}: phpc deploy -> ${dist}"
  "$PHPC" deploy "$project" -o "$dist"

  if [[ ! -x "${dist}/bin/app" ]]; then
    echo "deploy-smoke: ${label}: expected executable ${dist}/bin/app" >&2
    exit 1
  fi
  if [[ ! -f "$readme" ]]; then
    echo "deploy-smoke: ${label}: expected ${readme}" >&2
    exit 1
  fi
  if ! grep -q 'PHPC_DEPLOY_ROOT' "$readme"; then
    echo "deploy-smoke: ${label}: README.deploy missing PHPC_DEPLOY_ROOT" >&2
    exit 1
  fi

  local out
  case "$id" in
    001-SimpleWeb)
      out="$(run_deployed_app "${label}" "$dist" \
        'QUERY_STRING=name=DeploySmoke' \
        'REQUEST_METHOD=GET' \
        'SCRIPT_NAME=/example.php' \
        'REQUEST_URI=/example.php?name=DeploySmoke')"
      assert_needle "${label}" "$out" '<h1>Hello DeploySmoke</h1>'
      ;;
    002-StaticWeb)
      out="$(run_deployed_app "${label}" "$dist")"
      assert_needle "${label}" "$out" 'Hello World'
      ;;
    *)
      echo "deploy-smoke: internal error: unknown example id ${id}" >&2
      exit 1
      ;;
  esac

  echo "deploy-smoke: ${label}: ok"
}

smoke_003_miniwebapp() {
  if [[ ! -d "${MINIWEBAPP}/public" ]]; then
    echo "deploy-smoke: 003-MiniWebApp: skip (tree missing #246)" >&2
    return 0
  fi
  echo "deploy-smoke: 003-MiniWebApp: skip (AOT execute blocked #764; PHPUnit dist E2E #612)" >&2
  return 0
}

case "${EXAMPLE}" in
  001) smoke_deploy_example '001-SimpleWeb' 'examples/001-SimpleWeb' '001-SimpleWeb' ;;
  002) smoke_deploy_example '002-StaticWeb' 'examples/002-StaticWeb' '002-StaticWeb' ;;
  003) smoke_003_miniwebapp ;;
  *)
    echo "deploy-smoke: unknown --example ${EXAMPLE} (use 001, 002, or 003)" >&2
    exit 1
    ;;
esac

echo "deploy-smoke: ok"
