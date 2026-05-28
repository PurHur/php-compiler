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
REPO_ROOT="$(pwd)"
IMAGE="${PHP_COMPILER_DEV_IMAGE:-php-compiler:22.04-dev}"

# shellcheck source=selfhost-preflight.sh
source "$(dirname "$0")/selfhost-preflight.sh"
selfhost_preflight docker-exec docker-only

# shellcheck source=ci-docker-preflight.sh
source "$(dirname "$0")/ci-docker-preflight.sh"
ci_docker_preflight
ci_docker_acquire_single_ci_lock

# shellcheck source=ci-docker-run.sh
source "$(dirname "$0")/ci-docker-run.sh"

if [[ "${1:-}" == "--" ]]; then
  shift
fi

FORCE_BIND_MOUNT=0
if [[ "${1:-}" == "--bind" ]]; then
  FORCE_BIND_MOUNT=1
  shift
  if [[ "${1:-}" == "--" ]]; then
    shift
  fi
fi

SYNC_BACK_PATHS=()
if [[ "${1:-}" == "--sync-back" ]]; then
  shift
  while [[ $# -gt 0 ]] && [[ "${1:-}" != "--" ]]; do
    SYNC_BACK_PATHS+=("$1")
    shift
  done
  if [[ "${1:-}" == "--" ]]; then
    shift
  fi
fi

_run_in_container() {
  local inner="$1"
  # shellcheck disable=SC2086
  ci_docker_run -v "$(pwd):/compiler" -w /compiler "$IMAGE" bash -lc "$inner"
}

_llvm_exports='export PHP_COMPILER_LLVM_PATH=/opt/llvm9; export LD_LIBRARY_PATH=/opt/llvm9${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}; unset PHP_COMPILER_SKIP_LLVM_PRELOAD;'

if [[ $# -eq 0 ]]; then
  _run_in_container "source script/php-env.sh; ${_llvm_exports} exec bash"
  exit 0
fi

# When we fall back to tar-copy (no bind mount), generated docs won't persist unless we sync them back.
# Auto-sync the inventory docs for the common regeneration commands so that a follow-up `--check` works.
if [[ ${#SYNC_BACK_PATHS[@]} -eq 0 ]]; then
  case " $* " in
    *" script/bootstrap-inventory.php "*)
      SYNC_BACK_PATHS+=("docs/bootstrap-inventory.md")
      ;;
    *" script/bootstrap-vendor-inventory.php "*)
      SYNC_BACK_PATHS+=("docs/bootstrap-vendor-inventory.md")
      ;;
  esac
fi

quoted=$(printf '%q ' "$@")
inner="source script/php-env.sh; ${_llvm_exports} ${quoted}"

if [[ "$FORCE_BIND_MOUNT" -eq 1 ]]; then
  _run_in_container "$inner"
  exit $?
fi

# Bind-mount completeness probe: some harness hosts report a partial tree where a few files exist
# but script/ paths are missing (#272). Require a couple of repo-sentinel files before trusting the mount.
if [[ -f vendor/bin/phpunit ]] && ci_docker_run -v "$(pwd):/compiler" -w /compiler "$IMAGE" bash -lc "test -f vendor/bin/phpunit && test -f script/ci-local.sh && test -f script/bootstrap-selfhost-cli-driver-emit.sh" 2>/dev/null; then
  _run_in_container "$inner"
  exit $?
fi

echo "docker-exec: bind-mount incomplete; copying repo via tar..." >&2
# shellcheck disable=SC2086
if [[ "${#SYNC_BACK_PATHS[@]}" -gt 0 ]]; then
  sync_quoted=$(printf '%q ' "${SYNC_BACK_PATHS[@]}")
  # Stream the repo into a throwaway container, run the command, then stream selected paths back.
  # Use docker create/start + trap cleanup so tar-fallback never leaks long-lived containers (#2708).
  container_id="$(ci_docker_create -i -w /compiler "$IMAGE" bash -c "
    set -euo pipefail
    tar -xf -
    chmod +x bin/*.php script/*.sh 2>/dev/null || true
    ${_llvm_exports}
    source script/php-env.sh
    ${quoted} 1>&2
    tar -cf - ${sync_quoted}
  ")"
  trap 'docker rm -f "${container_id}" >/dev/null 2>&1 || true' EXIT INT TERM
  set +e
  tar -cf - --exclude='.git' --exclude='.llvm' . | docker start -ai "${container_id}" | tar -xf -
  status=$?
  set -e
  docker rm -f "${container_id}" >/dev/null 2>&1 || true
  exit "$status"
else
  container_id="$(ci_docker_create -i -w /compiler "$IMAGE" bash -c "
    set -euo pipefail
    tar -xf -
    chmod +x bin/*.php script/*.sh 2>/dev/null || true
    ${_llvm_exports}
    source script/php-env.sh
    ${quoted}
  ")"
  trap 'docker rm -f "${container_id}" >/dev/null 2>&1 || true' EXIT INT TERM
  set +e
  tar -cf - --exclude='.git' --exclude='.llvm' . | docker start -ai "${container_id}"
  status=$?
  set -e
  docker rm -f "${container_id}" >/dev/null 2>&1 || true
  exit "$status"
fi
