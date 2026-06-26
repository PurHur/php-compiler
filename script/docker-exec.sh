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

# Hosts sometimes copy/paste `docker info` into the inner shell; the dev image has no docker CLI (#2674, #2757).
_docker_exec_reject_nested_docker() {
  local joined=" $* "
  case "${joined}" in
    *" docker info"*|*" docker run"*|*" docker exec"*|*" docker start"*|*" docker build"*|\
    *" docker pull"*|*" docker push"*|*" docker cp"*|*" docker wait"*|*" docker logs"*)
      echo "docker-exec: environment misuse (not a compiler failure): do not run 'docker' inside docker-exec" >&2
      echo "docker-exec: run 'docker info' on the host first, then invoke make/php inside the container only." >&2
      echo "docker-exec: ./script/bootstrap-selfhost-gate.sh link  # no host make/php (#2905)" >&2
      echo "docker-exec: see issues #2674 and #2757 · tracker #1492" >&2
      exit 1
      ;;
  esac
}

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

# Self-host bootstrap uses compiled drivers under build/ across multiple docker-exec invocations.
# In tar-fallback mode the container is ephemeral, so keep the key driver artifacts on the host (#2963).
_docker_exec_m5_sync_back_paths() {
  SYNC_BACK_PATHS+=(
    "build/bin-compile-aot"
    "build/bin-compile-aot-inventory"
    "build/.m3_bin_compile_aot_blob"
    "build/.m3_compiler_minimal_aot_blob"
    "build/selfhost"
    "build/selfhost-compile-driver"
    "build/selfhost-native-compile-driver"
    "build/selfhost-helloworld-compile"
    "build/selfhost-lib-spine-smoke"
  )
}
if [[ ${#SYNC_BACK_PATHS[@]} -eq 0 ]]; then
  case " $* " in
    *bootstrap-gen0-refresh-sidecar*)
      SYNC_BACK_PATHS+=("prelinked/bootstrap-gen0")
      ;;
    *north-star5-verify*|*north-star3-verify*|\
    *bootstrap-vendor-objects.php*|*bootstrap-vendor-prelink-*|\
    *bootstrap-selfhost-link*|*bootstrap-selfhost-driver-smoke*|\
    *bootstrap-selfhost-helloworld-compile-bin*|\
    *bootstrap-selfhost-lib-spine-smoke*|*bootstrap-selfhost-full-revision-probe*|\
    *bootstrap-gen0-refresh-sidecar*|*bootstrap-loop-*)
      _docker_exec_m5_sync_back_paths
      ;;
  esac
fi

if [[ $# -gt 0 ]]; then
  _docker_exec_reject_nested_docker "$@"
fi

quoted=$(printf '%q ' "$@")
selfhost_preflight_warn_nested_docker "$@"
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

_tar_fallback_inner="
  set -euo pipefail
  tar -xf -
  chmod +x bin/*.php script/*.sh 2>/dev/null || true
  ${_llvm_exports}
  source script/php-env.sh
  ${quoted}
"

echo "docker-exec: bind-mount incomplete; copying repo via tar..." >&2
# shellcheck disable=SC2086
if [[ "${#SYNC_BACK_PATHS[@]}" -gt 0 ]]; then
  # Stream the repo into a throwaway container, run the command, then sync selected paths back.
  # NOTE: `docker start -ai` multiplexes stdout+stderr, so streaming a tarball back can be corrupted
  # by any stderr output. Use `docker cp` for sync-back instead so bootstrap tools can print freely.
  # Use docker create/start + trap cleanup so tar-fallback never leaks long-lived containers (#2708).
  container_id=""
  _tar_fallback_cleanup() {
    if [[ -n "${container_id:-}" ]]; then
      docker rm -f "${container_id}" >/dev/null 2>&1 || true
    fi
  }
  container_id="$(ci_docker_create \
    --label php-compiler.tar-fallback=1 \
    -i -w /compiler "$IMAGE" bash -c "${_tar_fallback_inner}")"
  trap _tar_fallback_cleanup EXIT INT TERM
  set +e
  tar -cf - --exclude='.git' --exclude='.llvm' . | docker start -ai "${container_id}"
  status="$(docker wait "${container_id}" 2>/dev/null || echo 1)"
  docker logs "${container_id}" 1>&2
  set -e
  for p in "${SYNC_BACK_PATHS[@]}"; do
    mkdir -p "$(dirname "${p}")"
    docker cp "${container_id}:/compiler/${p}" "${p}" >/dev/null 2>&1 || true
  done
  _tar_fallback_cleanup
  trap - EXIT INT TERM
  exit "$status"
else
  # No sync-back: use ci_docker_run (--rm) so Docker removes the container on exit (#2708).
  tar -cf - --exclude='.git' --exclude='.llvm' . | ci_docker_run -i -w /compiler "$IMAGE" bash -c "${_tar_fallback_inner}"
fi
