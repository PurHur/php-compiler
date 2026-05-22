#!/usr/bin/env bash
# Shared docker run wrapper: cgroup memory cap + CI env defaults (issue #497).
set -euo pipefail

_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=ci-defaults.env
source "$_SCRIPT_DIR/ci-defaults.env"

# Usage: ci_docker_run [docker run flags and image...] -- command...
ci_docker_run() {
  docker run --rm \
    -m "${PHP_COMPILER_DOCKER_MEM}" \
    --memory-swap "${PHP_COMPILER_DOCKER_MEM_SWAP}" \
    -e "PHP_COMPILER_CI_RAM_GB=${PHP_COMPILER_CI_RAM_GB}" \
    -e "PHP_COMPILER_MEMORY_LIMIT=${PHP_COMPILER_MEMORY_LIMIT}" \
    -e "PHP_COMPILER_LLVM_MEMORY_LIMIT=${PHP_COMPILER_LLVM_MEMORY_LIMIT}" \
    -e "PHP_COMPILER_SKIP_SERVE_TESTS=${PHP_COMPILER_SKIP_SERVE_TESTS:-}" \
    -e "JIT_PREFLIGHT_GATE=${JIT_PREFLIGHT_GATE:-}" \
    "$@"
}
