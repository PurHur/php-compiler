#!/usr/bin/env bash
# Run script/ci-local.sh (or ci-fast.sh) inside the PHP 8.2 dev container (issues #202, #73, #272).
# Harness hosts where bind-mounts appear empty can pipe the repo via tar instead.
# Preferred entrypoint on Runforge: make test-harness (same as this script).
# Usage: ./script/docker-ci-local.sh [fast] [phpunit args...]
set -euo pipefail
cd "$(dirname "$0")/.."
IMAGE="${PHP_COMPILER_DEV_IMAGE:-php-compiler:22.04-dev}"

CI_SCRIPT=ci-local.sh
if [[ "${1:-}" == "fast" ]]; then
  CI_SCRIPT=ci-fast.sh
  shift
fi

if ! docker image inspect "$IMAGE" >/dev/null 2>&1; then
  echo "Building dev image $IMAGE (make docker-build-22)..."
  make docker-build-22
fi

if [[ -f vendor/bin/phpunit ]] && docker run --rm -v "$(pwd):/compiler" -w /compiler "$IMAGE" test -f "script/${CI_SCRIPT}" 2>/dev/null; then
  exec docker run --rm -v "$(pwd):/compiler" -w /compiler "$IMAGE" bash "script/${CI_SCRIPT}" "$@"
fi

echo "Bind-mount has no vendor/; copying repo into container via tar..."
quoted=$(printf '%q ' "$@")
tar -cf - --exclude='.git' --exclude='.llvm' . | docker run --rm -i -w /compiler "$IMAGE" bash -c "tar -xf - && ./script/${CI_SCRIPT} ${quoted}"
