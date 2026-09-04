#!/usr/bin/env bash
# Entrypoint for ghcr.io/purhur/phpc — user project is mounted at /app (#36390).
set -euo pipefail

PHPC_HOME="${PHPC_HOME:-/opt/phpc}"
export PHP_COMPILER_LLVM_PATH="${PHP_COMPILER_LLVM_PATH:-/opt/llvm9}"
export LD_LIBRARY_PATH="${LD_LIBRARY_PATH:-}:${PHP_COMPILER_LLVM_PATH}"

if [[ -d /app ]]; then
  export PHPC_INVOKE_CWD="${PHPC_INVOKE_CWD:-/app}"
  cd /app
else
  export PHPC_INVOKE_CWD="${PHPC_INVOKE_CWD:-${PHPC_HOME}}"
  cd "${PHPC_HOME}"
fi

exec "${PHPC_HOME}/phpc" "$@"
