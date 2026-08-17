#!/usr/bin/env bash
# Host-friendly bootstrap gate entrypoints without requiring host `make` (#2905).
#
# Prefer this on minimal harness hosts (no make/php on PATH) when Docker is available:
#   docker info >/dev/null          # host preflight — not inside docker-exec
#   ./script/bootstrap-selfhost-gate.sh link
#
# Maps common `make bootstrap-*` targets to their underlying shell scripts.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# shellcheck source=selfhost-preflight.sh
source "$(dirname "$0")/selfhost-preflight.sh"

bootstrap_selfhost_gate_usage() {
  cat >&2 <<EOF
bootstrap-selfhost-gate: run self-host probes without host make/php (#2905)

Usage:
  ./script/bootstrap-selfhost-gate.sh link
  ./script/bootstrap-selfhost-gate.sh helloworld
  ./script/bootstrap-selfhost-gate.sh inventory-check
  ./script/bootstrap-selfhost-gate.sh loop-probe-dry

When host make/php are missing but Docker works:
  docker info >/dev/null
  ./script/docker-exec.sh -- bash -lc './script/bootstrap-selfhost-gate.sh link'

When gen-0 is lowering-stale, link falls back to Zend AOT of compiler_minimal and needs
16g Docker memory (default 10g SIGKILLs — #31714 / helloworld sibling #23970):
  PHP_COMPILER_DOCKER_MEM=16g PHP_COMPILER_DOCKER_MEM_SWAP=16g \\
    ./script/docker-exec.sh -- bash -lc './script/bootstrap-selfhost-gate.sh link'

Do not nest 'docker info' or 'docker run' inside docker-exec — the container has PHP/LLVM only.
Missing Docker CLI: see issue #2674.
EOF
}

bootstrap_selfhost_gate_inventory_check() {
  if command -v php >/dev/null 2>&1; then
    php "${ROOT}/script/bootstrap-inventory.php" --check
    return
  fi
  if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
    "${ROOT}/script/docker-exec.sh" -- bash -lc 'php script/bootstrap-inventory.php --check'
    return
  fi
  selfhost_preflight bootstrap-inventory-check php-or-docker
}

bootstrap_selfhost_gate_dispatch() {
  local gate="${1:-}"
  case "${gate}" in
    link)
      exec "${ROOT}/script/bootstrap-selfhost-link.sh"
      ;;
    helloworld)
      exec env \
        BOOTSTRAP_M3_LINK_COMPILE_DRIVER=1 \
        BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING=1 \
        BOOTSTRAP_M3_RUNTIME_COMPILE=1 \
        BOOTSTRAP_M3_HELLOWORLD_STRICT=1 \
        "${ROOT}/script/bootstrap-selfhost-helloworld-probe.sh"
      ;;
    inventory-check)
      bootstrap_selfhost_gate_inventory_check
      ;;
    loop-probe-dry)
      exec "${ROOT}/script/bootstrap-loop-probe.sh" --dry-run
      ;;
    help|-h|--help|'')
      bootstrap_selfhost_gate_usage
      exit "${gate:+0}"
      ;;
    *)
      echo "bootstrap-selfhost-gate: unknown gate: ${gate}" >&2
      bootstrap_selfhost_gate_usage
      exit 1
      ;;
  esac
}

if [[ "${1:-}" == "--" ]]; then
  shift
fi

bootstrap_selfhost_gate_dispatch "${1:-}"
