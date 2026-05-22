#!/usr/bin/env bash
# Run CI inside Docker with cgroup memory cap (default entry for make test / docker-ci-local).
#
# Usage: ./script/ci-docker-safe.sh [ci-fast.sh | ci-local.sh] [args...]
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CI_SCRIPT="${1:-ci-fast.sh}"
shift || true

IMAGE="${PHP_COMPILER_DEV_IMAGE:-php-compiler:22.04-dev}"

# shellcheck source=ci-docker-run.sh
source "$(dirname "$0")/ci-docker-run.sh"

ci_docker_run \
  -v "$REPO_ROOT:/compiler" -w /compiler \
  "$IMAGE" "./script/${CI_SCRIPT}" "$@"
