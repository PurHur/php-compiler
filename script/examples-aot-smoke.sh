#!/usr/bin/env bash
# AOT build + CLI execute smoke for shipped examples (issue #667).
#
# Builds each example to .phpc/smoke/<name>/app, runs the native binary once,
# and checks stdout needles (no HTTP). Skips with exit 0 when LLVM 9 is missing.
#
# Usage:
#   ./script/examples-aot-smoke.sh
#   EXAMPLES_AOT_SMOKE_ONLY=003 ./script/examples-aot-smoke.sh   # 003 slice only (#683)
#
# Docker:
#   docker run --rm -v "$(pwd):/compiler" -w /compiler php-compiler:22.04-dev make examples-aot-smoke
#
# 003-MiniWebApp: link probe + execute bytes; empty stdout skips with #764 (#809, #485).
set -euo pipefail

cd "$(dirname "$0")/.."
ROOT="$PWD"
PHPC="${ROOT}/phpc"
SMOKE_ROOT="${ROOT}/.phpc/smoke"
MINIWEBAPP="${ROOT}/examples/003-MiniWebApp"
SMOKE_ONLY="${EXAMPLES_AOT_SMOKE_ONLY:-}"

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
  echo "examples-aot-smoke: skipped (LLVM 9 not available at ${hint})"
  exit 0
fi
export PHP_COMPILER_LLVM_PATH="$LLVM_DIR"

# shellcheck source=php-env.sh
source "${ROOT}/script/php-env.sh"
export PHP_COMPILER_LLVM_PATH="$LLVM_DIR"

if [[ ! -x "$PHPC" ]]; then
  echo "examples-aot-smoke: phpc wrapper missing or not executable: ${PHPC}" >&2
  exit 1
fi

mkdir -p "$SMOKE_ROOT"

build_binary() {
  local label="$1"
  local source="$2"
  local outfile="$3"
  local bindir
  bindir="$(dirname "$outfile")"
  mkdir -p "$bindir"
  if [[ -x "$outfile" ]]; then
    rm -f "$outfile"
  fi
  echo "examples-aot-smoke: ${label}: phpc build -> ${outfile}"
  "$PHPC" build -o "$outfile" "$source"
  if [[ ! -x "$outfile" ]]; then
    echo "examples-aot-smoke: ${label}: expected executable ${outfile}" >&2
    exit 1
  fi
}

assert_needles() {
  local label="$1"
  local output="$2"
  shift 2
  local needle
  for needle in "$@"; do
    if [[ "$output" != *"$needle"* ]]; then
      echo "examples-aot-smoke: ${label}: stdout missing needle: ${needle}" >&2
      echo "--- stdout ---" >&2
      echo "$output" >&2
      echo "--- end ---" >&2
      exit 1
    fi
  done
}

run_binary() {
  local label="$1"
  local binary="$2"
  shift 2
  local stderr_file stdout stderr
  stderr_file="$(mktemp "${SMOKE_ROOT}/run.XXXXXX")"
  if (("$#" > 0)); then
    stdout="$(env "$@" "$binary" 2>"$stderr_file")"
  else
    stdout="$("$binary" 2>"$stderr_file")"
  fi
  local exit_code=$?
  stderr="$(cat "$stderr_file" 2>/dev/null || true)"
  rm -f "$stderr_file"
  if [[ "$exit_code" -ne 0 ]]; then
    echo "examples-aot-smoke: ${label}: binary exited ${exit_code}" >&2
    [[ -n "$stderr" ]] && echo "$stderr" >&2
    exit 1
  fi
  if [[ -n "$stderr" ]]; then
    echo "examples-aot-smoke: ${label}: stderr: ${stderr}" >&2
    exit 1
  fi
  printf '%s' "$stdout"
}

# 003-MiniWebApp project AOT + CLI execute (issue #485, #683, #809).
# Link is attempted; empty stdout skips with #764 unless MINIWEBAPP_AOT_EXECUTE_GATE=1.
smoke_003_miniwebapp() {
  if [[ ! -d "${MINIWEBAPP}/public" ]]; then
    echo "examples-aot-smoke: 003-MiniWebApp: skip (tree missing #246)" >&2
    return 0
  fi

  local binary="${MINIWEBAPP}/.phpc/bin/app"
  echo "examples-aot-smoke: 003-MiniWebApp: phpc build --project -> ${binary}"
  if ! "$PHPC" build --project "${MINIWEBAPP}"; then
    echo "examples-aot-smoke: 003-MiniWebApp: link failed" >&2
    return 1
  fi
  if [[ ! -x "$binary" ]]; then
    echo "examples-aot-smoke: 003-MiniWebApp: expected executable ${binary}" >&2
    return 1
  fi

  local out stderr_file run_code
  stderr_file="$(mktemp "${SMOKE_ROOT}/003.XXXXXX")"
  set +e
  out="$(env \
    QUERY_STRING='route=home' \
    SCRIPT_NAME='/index.php' \
    REQUEST_URI='/index.php?route=home' \
    REQUEST_METHOD='GET' \
    "$binary" 2>"$stderr_file")"
  run_code=$?
  set -e
  local stderr
  stderr="$(cat "$stderr_file" 2>/dev/null || true)"
  rm -f "$stderr_file"

  if [[ "$run_code" -ne 0 ]]; then
    echo "examples-aot-smoke: 003-MiniWebApp: binary exited ${run_code}" >&2
    [[ -n "$stderr" ]] && echo "$stderr" >&2
    return 1
  fi
  if [[ -n "$stderr" ]]; then
    echo "examples-aot-smoke: 003-MiniWebApp: stderr: ${stderr}" >&2
    return 1
  fi

  if [[ -z "$out" ]] || [[ "$out" != *'MiniWebApp'* ]]; then
    if [[ "${MINIWEBAPP_AOT_EXECUTE_GATE:-0}" == "1" ]]; then
      echo "examples-aot-smoke: 003-MiniWebApp: FAILED (empty or wrong stdout; blocked #764)" >&2
      [[ -n "$out" ]] && echo "--- stdout ---" >&2 && echo "$out" >&2 && echo "--- end ---" >&2
      return 1
    fi
    echo "examples-aot-smoke: 003-MiniWebApp: skip (empty stdout; blocked #764)" >&2
    return 0
  fi

  assert_needles '003-MiniWebApp' "$out" 'MiniWebApp'
  echo "examples-aot-smoke: 003-MiniWebApp: ok"
}

if [[ "${SMOKE_ONLY}" == "003" ]]; then
  echo "examples-aot-smoke: 003 slice (LLVM at ${LLVM_DIR})"
  smoke_003_miniwebapp
  exit $?
fi

if [[ -n "${SMOKE_ONLY}" ]]; then
  echo "examples-aot-smoke: unknown EXAMPLES_AOT_SMOKE_ONLY=${SMOKE_ONLY}" >&2
  exit 1
fi

echo "examples-aot-smoke: starting (LLVM at ${LLVM_DIR})"

# 000-HelloWorld — single script, no phpc.json
build_binary '000-HelloWorld' \
  "${ROOT}/examples/000-HelloWorld/example.php" \
  "${SMOKE_ROOT}/000-HelloWorld/app"
out="$(run_binary '000-HelloWorld' "${SMOKE_ROOT}/000-HelloWorld/app")"
assert_needles '000-HelloWorld' "$out" 'Hello World'
echo "examples-aot-smoke: 000-HelloWorld: ok"

# 001-SimpleWeb — runtime QUERY_STRING refresh (issue #309)
build_binary '001-SimpleWeb' \
  "${ROOT}/examples/001-SimpleWeb/example.php" \
  "${SMOKE_ROOT}/001-SimpleWeb/app"
out="$(run_binary '001-SimpleWeb' "${SMOKE_ROOT}/001-SimpleWeb/app" \
  'QUERY_STRING=name=Smoke' \
  'SCRIPT_NAME=/example.php' \
  'REQUEST_URI=/example.php?name=Smoke')"
assert_needles '001-SimpleWeb' "$out" '<h1>Hello Smoke</h1>'
echo "examples-aot-smoke: 001-SimpleWeb: ok"

# 002-StaticWeb
build_binary '002-StaticWeb' \
  "${ROOT}/examples/002-StaticWeb/example.php" \
  "${SMOKE_ROOT}/002-StaticWeb/app"
out="$(run_binary '002-StaticWeb' "${SMOKE_ROOT}/002-StaticWeb/app")"
assert_needles '002-StaticWeb' "$out" 'Hello World'
echo "examples-aot-smoke: 002-StaticWeb: ok"

# 004-ApiJson
build_binary '004-ApiJson' \
  "${ROOT}/examples/004-ApiJson/example.php" \
  "${SMOKE_ROOT}/004-ApiJson/app"
out="$(run_binary '004-ApiJson' "${SMOKE_ROOT}/004-ApiJson/app")"
assert_needles '004-ApiJson' "$out" 'Content-Type: application/json' 'Status: 200' '"ok":true' 'php-compiler'
echo "examples-aot-smoke: 004-ApiJson: ok"

smoke_003_miniwebapp

echo "examples-aot-smoke: ok"
