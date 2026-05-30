#!/usr/bin/env bash
# Ensure build/bin-compile-aot-inventory — SSOT inventory argv driver for M2–M4 bootstrap (#2968).
#
# Usage:
#   ./script/bootstrap-ensure-inventory-argv-driver.sh [OUT]
#   source ./script/bootstrap-ensure-inventory-argv-driver.sh && bootstrap_ensure_inventory_argv_driver_ssot
#
# Sets BOOTSTRAP_COMPILE_DRIVER and BOOTSTRAP_COMPILE_DRIVER_MODE when sourced or executed.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# shellcheck source=php-env.sh
source "${ROOT}/script/php-env.sh"
# shellcheck source=bootstrap-resolve-compile-invoke.sh
source "${ROOT}/script/bootstrap-resolve-compile-invoke.sh"

bootstrap_ensure_inventory_argv_driver_ssot() {
  local out="${1:-${ROOT}/build/bin-compile-aot-inventory}"
  if ! bootstrap_ensure_inventory_argv_driver "${out}"; then
    echo "bootstrap-ensure-inventory-argv-driver: failed ${out}" >&2
    return 1
  fi
  export BOOTSTRAP_COMPILE_DRIVER="${out}"
  export BOOTSTRAP_COMPILE_DRIVER_MODE=native
  echo "bootstrap-ensure-inventory-argv-driver: OK ${BOOTSTRAP_COMPILE_DRIVER} (#2968)"
}

if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
  ci_apply_llvm_memory_env
  bootstrap_ensure_inventory_argv_driver_ssot "${1:-}"
fi
