#!/usr/bin/env bash
# Docker preflight for harness-safe wrappers (issue #2246).
#
# - Fail fast when `docker info` is unavailable
# - Best-effort single CI container at a time via flock on a workspace lockfile
#
# Opt-out:
#   PHP_COMPILER_CI_SINGLE_CONTAINER=0   skip lock (parallel runs at your own risk)
#   PHP_COMPILER_CI_VERBOSE=1          one-line status on success
set -euo pipefail

ci_docker_preflight() {
  if docker info >/dev/null 2>&1; then
    if [[ "${PHP_COMPILER_CI_VERBOSE:-0}" = "1" ]]; then
      echo "ci-docker-preflight: docker OK" >&2
    fi
    return 0
  fi

  echo "ci-docker-preflight: docker info failed — is the Docker daemon running?" >&2
  echo "ci-docker-preflight: run: docker info" >&2
  echo "ci-docker-preflight: fix Docker, then retry make test-harness / ./script/docker-ci-local.sh / ./script/docker-exec.sh" >&2
  exit 1
}

ci_docker_acquire_single_ci_lock() {
  if [[ "${PHP_COMPILER_CI_SINGLE_CONTAINER:-1}" != "1" ]]; then
    return 0
  fi

  local root="${PHP_COMPILER_REPO_ROOT:-${REPO_ROOT:-$(pwd)}}"
  local lockfile="${PHP_COMPILER_CI_LOCK_FILE:-${root}/.php-compiler-ci.lock}"
  mkdir -p "$(dirname "$lockfile")"

  _ci_docker_lock_fd=200
  exec 200>"$lockfile"
  if flock -n 200; then
    printf '%s %s\n' "$$" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" >"$lockfile"
    if [[ "${PHP_COMPILER_CI_VERBOSE:-0}" = "1" ]]; then
      echo "ci-docker-preflight: acquired CI lock (${lockfile})" >&2
    fi
    return 0
  fi

  local holder=""
  if [[ -r "$lockfile" ]]; then
    holder=$(head -n 1 "$lockfile" 2>/dev/null || true)
  fi
  echo "ci-docker-preflight: another CI/Docker wrapper run is active (lock: ${lockfile})" >&2
  if [[ -n "$holder" ]]; then
    echo "ci-docker-preflight: lock holder: ${holder}" >&2
  fi
  echo "ci-docker-preflight: wait for the other run to finish, or stop its container, then retry" >&2
  echo "ci-docker-preflight: opt-out (not recommended): PHP_COMPILER_CI_SINGLE_CONTAINER=0" >&2
  exit 1
}
