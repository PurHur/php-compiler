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

  # Stale-lock guard (issue #2688): if a previous wrapper crashed, the lockfile may remain
  # but no process should still hold the flock. In that case, remove and retry once.
  local stale_after="${PHP_COMPILER_CI_LOCK_STALE_AFTER_SEC:-1800}"
  local did_retry="${_CI_DOCKER_LOCK_STALE_RETRY:-0}"

  _ci_docker_lock_fd=200
  # IMPORTANT: do not truncate the lockfile before we acquire the flock.
  # Using `> lockfile` clears it even when another process is holding the lock,
  # leaving an empty file that can persist and confuse subsequent runs (#2975).
  touch "$lockfile"
  exec 200<>"$lockfile"

  _ci_docker_lock_mark_held() {
    printf '%s %s\n' "$$" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" >"$lockfile"
    # Best-effort hygiene: ensure an interrupted wrapper does not leave a confusing lock file behind.
    # The actual lock is held by the file descriptor and is released automatically on process exit.
    if [[ -z "${_CI_DOCKER_LOCK_TRAP_INSTALLED:-}" ]]; then
      export _CI_DOCKER_LOCK_TRAP_INSTALLED=1
      export _CI_DOCKER_LOCKFILE="$lockfile"
      trap 'rm -f "${_CI_DOCKER_LOCKFILE:-}" 2>/dev/null || true' EXIT INT TERM
    fi
    if [[ "${PHP_COMPILER_CI_VERBOSE:-0}" = "1" ]]; then
      echo "ci-docker-preflight: acquired CI lock (${lockfile})" >&2
    fi
  }

  if flock -n 200; then
    _ci_docker_lock_mark_held
    return 0
  fi

  # RunForge agents often fire two docker-exec/phpunit.sh calls in parallel.
  # Wait instead of failing instantly so the second wrapper serializes.
  local wait_sec="${PHP_COMPILER_CI_LOCK_WAIT_SEC:-120}"
  echo "ci-docker-preflight: CI lock busy; waiting up to ${wait_sec}s (${lockfile})" >&2
  if flock -w "${wait_sec}" 200; then
    _ci_docker_lock_mark_held
    return 0
  fi

  local holder=""
  local holder_pid=""
  local holder_ts=""
  if [[ -r "$lockfile" ]]; then
    holder=$(head -n 1 "$lockfile" 2>/dev/null || true)
    holder_pid=$(awk '{print $1}' <<< "$holder" 2>/dev/null || true)
    holder_ts=$(awk '{print $2}' <<< "$holder" 2>/dev/null || true)
  fi
  echo "ci-docker-preflight: another CI/Docker wrapper run is active (lock: ${lockfile})" >&2
  if [[ -n "$holder" ]]; then
    echo "ci-docker-preflight: lock holder: ${holder}" >&2
  fi
  if command -v stat >/dev/null 2>&1; then
    # GNU coreutils: stat -c %Y yields epoch seconds.
    local mtime=""
    mtime=$(stat -c %Y "$lockfile" 2>/dev/null || true)
    local size=""
    size=$(stat -c %s "$lockfile" 2>/dev/null || true)
    if [[ -n "$mtime" ]] && [[ "$mtime" =~ ^[0-9]+$ ]]; then
      local now=""
      now=$(date +%s 2>/dev/null || true)
      if [[ -n "$now" ]] && [[ "$now" =~ ^[0-9]+$ ]]; then
        local age=$(( now - mtime ))
        if (( age >= 0 )); then
          echo "ci-docker-preflight: lock age: ${age}s" >&2
          # Empty/partial lockfiles can be left behind if a wrapper dies between acquiring the flock
          # and writing holder metadata. Treat a small, older-than-a-blip lockfile as stale and retry.
          if [[ "$did_retry" != "1" ]] \
            && [[ -n "$size" ]] && [[ "$size" =~ ^[0-9]+$ ]] \
            && (( size == 0 )) && (( age >= 5 ))
          then
            echo "ci-docker-preflight: lockfile is empty (size 0) and older than 5s; attempting one-time cleanup + retry" >&2
            rm -f "$lockfile" 2>/dev/null || true
            export _CI_DOCKER_LOCK_STALE_RETRY=1
            ci_docker_acquire_single_ci_lock
            return $?
          fi
          if [[ "$did_retry" != "1" ]] \
            && [[ -n "$stale_after" ]] && [[ "$stale_after" =~ ^[0-9]+$ ]] \
            && (( age >= stale_after ))
          then
            if [[ -n "$holder_pid" ]] && [[ "$holder_pid" =~ ^[0-9]+$ ]] && ! kill -0 "$holder_pid" 2>/dev/null; then
              echo "ci-docker-preflight: lock appears stale (pid ${holder_pid} not running; age ${age}s >= ${stale_after}s)" >&2
              echo "ci-docker-preflight: attempting one-time stale cleanup + retry" >&2
              rm -f "$lockfile" 2>/dev/null || true
              export _CI_DOCKER_LOCK_STALE_RETRY=1
              ci_docker_acquire_single_ci_lock
              return $?
            fi
            if [[ -z "$holder_pid" ]]; then
              echo "ci-docker-preflight: lock appears stale (missing holder pid; age ${age}s >= ${stale_after}s)" >&2
              echo "ci-docker-preflight: attempting one-time stale cleanup + retry" >&2
              rm -f "$lockfile" 2>/dev/null || true
              export _CI_DOCKER_LOCK_STALE_RETRY=1
              ci_docker_acquire_single_ci_lock
              return $?
            fi
          fi
        fi
      fi
    fi
  fi
  echo "ci-docker-preflight: wait for the other run to finish, or stop its container, then retry" >&2
  echo "ci-docker-preflight: do not run host vendor/bin/phpunit while waiting — use ./script/phpunit.sh after the lock clears" >&2
  echo "ci-docker-preflight: safe cleanup (only if you're sure nothing is running): rm -f ${lockfile}" >&2
  echo "ci-docker-preflight: opt-out (not recommended): PHP_COMPILER_CI_SINGLE_CONTAINER=0" >&2
  exit 1
}
