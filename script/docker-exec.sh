#!/usr/bin/env bash
# Run commands in php-compiler:22.04-dev with tar fallback when the bind-mount is incomplete (#272).
#
# When vendor/ is missing inside the mounted /compiler tree, copies the repo via tar
# (same approach as script/docker-ci-local.sh) and uses image LLVM at /opt/llvm9.
#
# Usage:
#   ./script/docker-exec.sh -- vendor/bin/phpunit test/unit/FooTest.php
#   ./script/docker-exec.sh -- bash -lc 'source script/php-env.sh && ./script/ci-local.sh'
#   ./script/docker-exec.sh          # interactive shell
set -euo pipefail
cd "$(dirname "$0")/.."
IMAGE="${PHP_COMPILER_DEV_IMAGE:-php-compiler:22.04-dev}"

# shellcheck source=ci-docker-run.sh
source "$(dirname "$0")/ci-docker-run.sh"

if [[ "${1:-}" == "--" ]]; then
  shift
fi

DOCKER_EXTRA=()
if [[ -n "${PHP_COMPILER_DOCKER_RUN_OPTS:-${HARNESS_DOCKER_RUN_OPTS:-}}" ]]; then
  # shellcheck disable=SC2206
  DOCKER_EXTRA=(${PHP_COMPILER_DOCKER_RUN_OPTS:-${HARNESS_DOCKER_RUN_OPTS}})
fi

_run_in_container() {
  local inner="$1"
  # shellcheck disable=SC2086
  ci_docker_run "${DOCKER_EXTRA[@]}" -v "$(pwd):/compiler" -w /compiler "$IMAGE" bash -lc "$inner"
}

_llvm_exports='export PHP_COMPILER_LLVM_PATH=/opt/llvm9; export LD_LIBRARY_PATH=/opt/llvm9${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}; unset PHP_COMPILER_SKIP_LLVM_PRELOAD;'

if [[ $# -eq 0 ]]; then
  _run_in_container "source script/php-env.sh; ${_llvm_exports} exec bash"
  exit 0
fi

quoted=$(printf '%q ' "$@")
inner="source script/php-env.sh; ${_llvm_exports} ${quoted}"

if [[ -f vendor/bin/phpunit ]] && ci_docker_run "${DOCKER_EXTRA[@]}" -v "$(pwd):/compiler" -w /compiler "$IMAGE" test -f vendor/bin/phpunit 2>/dev/null; then
  _run_in_container "$inner"
  exit $?
fi

echo "docker-exec: bind-mount incomplete; copying repo via tar..." >&2
# shellcheck disable=SC2086
tar -cf - --exclude='.git' --exclude='.llvm' . | ci_docker_run "${DOCKER_EXTRA[@]}" -i -w /compiler "$IMAGE" bash -c "
  tar -xf -
  chmod +x bin/*.php script/*.sh 2>/dev/null || true
  ${_llvm_exports}
  source script/php-env.sh
  ${quoted}
"
