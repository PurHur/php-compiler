#!/usr/bin/env bash
# Run CI inside Docker with cgroup memory cap so a leak cannot take the whole host.
#
# Usage: ./script/ci-docker-safe.sh [ci-fast.sh | ci-local.sh] [args...]
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CI_SCRIPT="${1:-ci-fast.sh}"
shift || true

MEM="${PHP_COMPILER_DOCKER_MEM:-10g}"
SWAP="${PHP_COMPILER_DOCKER_MEM_SWAP:-10g}"
IMAGE="${PHP_COMPILER_DEV_IMAGE:-php-compiler:22.04-dev}"

exec docker run --rm \
  -m "$MEM" --memory-swap "$SWAP" \
  -v "$REPO_ROOT:/compiler" -w /compiler \
  -e PHP_COMPILER_SKIP_SERVE_TESTS="${PHP_COMPILER_SKIP_SERVE_TESTS:-1}" \
  -e PHP_COMPILER_CI_RAM_GB="${PHP_COMPILER_CI_RAM_GB:-8}" \
  -e PHP_COMPILER_MEMORY_LIMIT="${PHP_COMPILER_MEMORY_LIMIT:-1536M}" \
  "$IMAGE" "./script/${CI_SCRIPT}" "$@"
