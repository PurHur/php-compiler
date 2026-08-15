#!/usr/bin/env bash
# Unified self-host environment preflight (#2810, #2674).
#
# Categorizes missing host prerequisites (docker CLI/daemon, php) before bootstrap gates
# so failures are not mistaken for compiler regressions.
#
# Usage (source from other scripts):
#   # shellcheck source=selfhost-preflight.sh
#   source "$(dirname "$0")/selfhost-preflight.sh"
#   selfhost_preflight "bootstrap-selfhost-link" php-or-docker
#   selfhost_preflight "docker-exec" docker-only
#
# Modes:
#   php-or-docker   host php required unless Docker path is available (bootstrap link/helloworld)
#   docker-only     docker CLI + daemon required (docker-exec / harness wrappers)
#   php-only        host php required (inventory-only host runs)
set -euo pipefail

_selfhost_preflight_have_docker() {
  command -v docker >/dev/null 2>&1
}

_selfhost_preflight_docker_daemon_ok() {
  docker info >/dev/null 2>&1
}

_selfhost_preflight_have_php() {
  command -v php >/dev/null 2>&1
}

_selfhost_preflight_print_docker_path() {
  local gate_cmd="${1:-make bootstrap-selfhost-link}"
  echo "selfhost-preflight: run inside the dev image (no host PHP/make required):" >&2
  echo "  ./script/docker-exec.sh -- bash -lc '${gate_cmd}'" >&2
  echo "selfhost-preflight: or without host make/php (gate wrapper, #2905):" >&2
  case "${gate_cmd}" in
    *helloworld*) echo "  ./script/bootstrap-selfhost-gate.sh helloworld" >&2 ;;
    *loop*) echo "  ./script/bootstrap-selfhost-gate.sh loop-probe-dry" >&2 ;;
    *inventory*) echo "  ./script/bootstrap-selfhost-gate.sh inventory-check" >&2 ;;
    *) echo "  ./script/bootstrap-selfhost-gate.sh link" >&2 ;;
  esac
  echo "selfhost-preflight: one-shot full ladder (after link is green):" >&2
  echo "  ./script/docker-exec.sh -- bash -lc 'make bootstrap-selfhost-helloworld'" >&2
  echo "  ./script/bootstrap-selfhost-gate.sh helloworld" >&2
  echo "selfhost-preflight: do not nest 'docker info' inside docker-exec — the container has PHP/LLVM only." >&2
  echo "selfhost-preflight: missing Docker CLI on host: see issue #2674." >&2
}

_selfhost_preflight_print_install_docker() {
  echo "selfhost-preflight: install Docker Engine, ensure 'docker info' succeeds on the host, then:" >&2
  echo "  make docker-build-22   # once: image php-compiler:22.04-dev" >&2
  echo "  docker info >/dev/null && ./script/docker-exec.sh -- bash -lc 'make bootstrap-selfhost-link'" >&2
  echo "selfhost-preflight: bootstrap probe recipe: https://github.com/PurHur/php-compiler/issues/1492 (#2674)" >&2
}

# Warn when docker-exec inner commands nest `docker` (container has no docker CLI — #2757).
selfhost_preflight_warn_nested_docker() {
  local cmd="$*"
  if [[ -z "${cmd}" ]]; then
    return 0
  fi
  # Match `docker` as a shell token (not path segments like docker-exec.sh).
  if [[ " ${cmd} " =~ (^|[[:space:]|;&()\"'])docker([[:space:]|;&)\"']|$) ]]; then
    echo "docker-exec: warning: 'docker' inside the inner command will fail — the dev image has PHP/LLVM only (#2757)." >&2
    echo "docker-exec: run 'docker info' on the **host** before docker-exec, not inside it:" >&2
    echo "  docker info >/dev/null && ./script/docker-exec.sh -- bash -lc 'make bootstrap-selfhost-link'" >&2
    echo "docker-exec: see https://github.com/PurHur/php-compiler/issues/1492 (#2674)" >&2
  fi
}

# selfhost_preflight <label> <mode>
# Returns 0 when prerequisites for <mode> are satisfied; exits 1 with one actionable block otherwise.
selfhost_preflight() {
  local label="${1:-self-host}"
  local mode="${2:-php-or-docker}"

  local have_docker=0 daemon_ok=0 have_php=0
  if _selfhost_preflight_have_docker; then
    have_docker=1
    if _selfhost_preflight_docker_daemon_ok; then
      daemon_ok=1
    fi
  fi
  if _selfhost_preflight_have_php; then
    have_php=1
  fi

  case "${mode}" in
    docker-only)
      if [[ "${have_docker}" -eq 0 ]]; then
        echo "selfhost-preflight: ${label}: environment prerequisites missing (not a compiler failure)" >&2
        echo "selfhost-preflight: missing: docker CLI" >&2
        _selfhost_preflight_print_install_docker
        exit 1
      fi
      if [[ "${daemon_ok}" -eq 0 ]]; then
        echo "selfhost-preflight: ${label}: environment prerequisites missing (not a compiler failure)" >&2
        echo "selfhost-preflight: docker CLI found but 'docker info' failed — is the daemon running?" >&2
        echo "selfhost-preflight: run: docker info" >&2
        _selfhost_preflight_print_install_docker
        exit 1
      fi
      return 0
      ;;
    php-only)
      if [[ "${have_php}" -eq 0 ]]; then
        echo "selfhost-preflight: ${label}: environment prerequisites missing (not a compiler failure)" >&2
        echo "selfhost-preflight: missing: php on host" >&2
        if [[ "${daemon_ok}" -eq 1 ]]; then
          _selfhost_preflight_print_docker_path "php script/bootstrap-inventory.php --check"
        else
          _selfhost_preflight_print_install_docker
        fi
        exit 1
      fi
      return 0
      ;;
    php-or-docker)
      if [[ "${have_php}" -eq 1 ]]; then
        return 0
      fi
      echo "selfhost-preflight: ${label}: environment prerequisites missing (not a compiler failure)" >&2
      echo "selfhost-preflight: missing: php on host" >&2
      if [[ "${daemon_ok}" -eq 1 ]]; then
        case "${label}" in
          *helloworld*) _selfhost_preflight_print_docker_path "make bootstrap-selfhost-helloworld" ;;
          *loop*) _selfhost_preflight_print_docker_path "make bootstrap-loop-probe-dry" ;;
          *) _selfhost_preflight_print_docker_path "make bootstrap-selfhost-link" ;;
        esac
        exit 1
      fi
      if [[ "${have_docker}" -eq 1 ]]; then
        echo "selfhost-preflight: docker CLI found but daemon not reachable — fix 'docker info' first." >&2
      else
        echo "selfhost-preflight: missing: docker (and php on host)" >&2
      fi
      _selfhost_preflight_print_install_docker
      exit 1
      ;;
    *)
      echo "selfhost-preflight: internal error: unknown mode ${mode}" >&2
      exit 1
      ;;
  esac
}

# Apply vendor patches once per process. Some self-host probes host-compile inventory/spine sources
# before the gen-0 link path runs apply-patches, which can trip on missing upstream ops (eg Union_).
selfhost_apply_patches_if_needed() {
  local root
  root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
  if [[ "${SELFHOST_APPLY_PATCHES_DONE:-0}" == "1" ]]; then
    return 0
  fi
  if [[ ! -f "${root}/script/apply-patches.sh" ]]; then
    export SELFHOST_APPLY_PATCHES_DONE=1
    return 0
  fi
  if [[ ! -d "${root}/vendor/ircmaxell/php-llvm" ]]; then
    # composer install not run yet; let the caller fail with a clearer message later.
    export SELFHOST_APPLY_PATCHES_DONE=1
    return 0
  fi
  chmod +x "${root}/script/apply-patches.sh" 2>/dev/null || true
  # Redirect stderr of the *invoking shell* too, so a crashing subprocess doesn't spam bootstrap logs.
  # Invoke via bash: some clones lose the git executable bit (100644) and `./apply-patches.sh` then
  # fails with Permission denied, aborting bootstrap-selfhost-link.
  { bash "${root}/script/apply-patches.sh" >/dev/null; } 2>/dev/null || true
  export SELFHOST_APPLY_PATCHES_DONE=1
}
