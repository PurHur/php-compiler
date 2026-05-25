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
    -e "WAVE3_ROADMAP_SYNC_GATE=${WAVE3_ROADMAP_SYNC_GATE:-1}" \
    -e "M2_SPINE_ISSUE_HYGIENE_GATE=${M2_SPINE_ISSUE_HYGIENE_GATE:-1}" \
    -e "EXAMPLES_README_SYNC_GATE=${EXAMPLES_README_SYNC_GATE:-1}" \
    -e "EXAMPLES_LADDER_DISCOVERY_GATE=${EXAMPLES_LADDER_DISCOVERY_GATE:-1}" \
    -e "ROOT_README_SYNC_GATE=${ROOT_README_SYNC_GATE:-1}" \
    -e "SELFHOST_SPINE_COUNT_SYNC_GATE=${SELFHOST_SPINE_COUNT_SYNC_GATE:-1}" \
    -e "M3_ALLOWLIST_SYNC_GATE=${M3_ALLOWLIST_SYNC_GATE:-1}" \
    -e "NESTED_RETURN_COMPLIANCE_GATE=${NESTED_RETURN_COMPLIANCE_GATE:-1}" \
    -e "ATTRIBUTES_COMPLIANCE_GATE=${ATTRIBUTES_COMPLIANCE_GATE:-1}" \
    "$@"
}
