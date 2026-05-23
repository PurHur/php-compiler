#!/usr/bin/env bash
# Build .phpc/bin/app for shipped web examples before examples-web-smoke.sh --aot (issue #444).
#
# Usage:
#   ./script/examples-web-smoke-prebuild.sh
#
# Requires LLVM 9 (same probe as ci-local.sh). Exits 0 with a skip message when LLVM is missing.
set -euo pipefail

cd "$(dirname "$0")/.."
ROOT="$PWD"
PHPC="${ROOT}/phpc"

# shellcheck source=php-env.sh
source "${ROOT}/script/php-env.sh"

LLVM_DIR="${PHP_COMPILER_LLVM_PATH:-${ROOT}/.llvm}"
if [[ ! -f "${LLVM_DIR}/libLLVM-9.so.1" ]]; then
  echo "examples-web-smoke-prebuild: skipped (LLVM 9 not available at ${LLVM_DIR})"
  exit 0
fi

if [[ ! -x "$PHPC" ]]; then
  echo "examples-web-smoke-prebuild: phpc wrapper missing or not executable: ${PHPC}" >&2
  exit 1
fi

WEB_EXAMPLES=(
  001-SimpleWeb
  002-StaticWeb
  003-MiniWebApp
  004-ApiJson
)

echo "examples-web-smoke-prebuild: building AOT binaries (phpc build --project)..."

for name in "${WEB_EXAMPLES[@]}"; do
  project_dir="${ROOT}/examples/${name}"
  if [[ ! -f "${project_dir}/phpc.json" ]]; then
    echo "examples-web-smoke-prebuild: ${name}: skip (no phpc.json)" >&2
    continue
  fi
  binary="${project_dir}/.phpc/bin/app"
  bin_dir="$(dirname "$binary")"
  mkdir -p "$bin_dir"
  echo "examples-web-smoke-prebuild: ${name} -> ${binary}"
  "$PHPC" build --project "$project_dir"
  if [[ ! -x "$binary" ]]; then
    echo "examples-web-smoke-prebuild: ${name}: expected executable ${binary}" >&2
    exit 1
  fi
done

echo "examples-web-smoke-prebuild: ok"
