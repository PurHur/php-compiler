#!/usr/bin/env bash
# Install vendor/ using the dev Docker image, safe on harness hosts where bind-mounts are empty.
#
# This script writes vendor/ into the host workspace by streaming it back as a tarball, so that
# subsequent docker-exec / bootstrap gates can use the normal bind-mount fast path.
#
# Usage:
#   ./script/docker-composer-install.sh
set -euo pipefail
cd "$(dirname "$0")/.."

IMAGE="${PHP_COMPILER_DEV_IMAGE:-php-compiler:22.04-dev}"

# shellcheck source=ci-docker-preflight.sh
source "$(dirname "$0")/ci-docker-preflight.sh"
ci_docker_preflight
ci_docker_acquire_single_ci_lock

# shellcheck source=ci-docker-run.sh
source "$(dirname "$0")/ci-docker-run.sh"

if ! docker image inspect "$IMAGE" >/dev/null 2>&1; then
  echo "Building dev image $IMAGE (make docker-build-22)..."
  make docker-build-22
fi

rm -rf vendor
mkdir -p vendor

# Stream vendor/ back to host so it persists between runs.
tar -cf - --exclude='.git' --exclude='.llvm' . | ci_docker_run -i -w /compiler "$IMAGE" bash -lc "
  set -euo pipefail
  tar -xf -
  composer install --ignore-platform-reqs --no-ansi --no-interaction --no-progress
  ./script/apply-patches.sh >/dev/null
  tar -cf - vendor
" | tar -xf -

test -f vendor/autoload.php
