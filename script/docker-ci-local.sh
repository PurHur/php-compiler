#!/usr/bin/env bash
# Run script/ci-local.sh (or ci-fast.sh) inside the PHP 8.2 dev container (issues #202, #73, #272).
# Harness hosts where bind-mounts appear empty can pipe the repo via tar instead.
# Preferred entrypoint on Runforge: make test-harness (same as this script).
# Usage: ./script/docker-ci-local.sh [fast] [phpunit args...]
#
# Always applies repository memory defaults (script/ci-defaults.env) and Docker -m cap.
set -euo pipefail
cd "$(dirname "$0")/.."
REPO_ROOT="$(pwd)"
IMAGE="${PHP_COMPILER_DEV_IMAGE:-php-compiler:22.04-dev}"

# shellcheck source=ci-docker-preflight.sh
source "$(dirname "$0")/ci-docker-preflight.sh"
ci_docker_preflight
ci_docker_acquire_single_ci_lock

# shellcheck source=ci-docker-run.sh
source "$(dirname "$0")/ci-docker-run.sh"

CI_SCRIPT=ci-local.sh
if [[ "${1:-}" == "fast" ]]; then
  CI_SCRIPT=ci-fast.sh
  shift
fi

if ! docker image inspect "$IMAGE" >/dev/null 2>&1; then
  echo "Building dev image $IMAGE (make docker-build-22)..."
  make docker-build-22
fi

if [[ -f vendor/bin/phpunit ]] && ci_docker_run -v "$(pwd):/compiler" -w /compiler "$IMAGE" test -f "script/${CI_SCRIPT}" 2>/dev/null; then
  ci_docker_run -v "$(pwd):/compiler" -w /compiler "$IMAGE" "./script/${CI_SCRIPT}" "$@"
  exit $?
fi

echo "Bind-mount has no vendor/; copying repo into container via tar..."
quoted=$(printf '%q ' "$@")
tar -cf - --exclude='.git' --exclude='.llvm' . | ci_docker_run -i -w /compiler "$IMAGE" bash -c "
  tar -xf -
  chmod +x bin/*.php script/*.sh 2>/dev/null || true
  export PHP_COMPILER_LLVM_PATH=/opt/llvm9
  export LD_LIBRARY_PATH=/opt/llvm9:\${LD_LIBRARY_PATH:-}
  unset PHP_COMPILER_SKIP_LLVM_PRELOAD
  ./script/${CI_SCRIPT} ${quoted}
"
