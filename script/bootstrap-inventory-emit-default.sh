#!/usr/bin/env bash
# Default-on inventory compile_driver.php for M3 emit-helper link (#3024, #2879).
#
# Usage:
#   # shellcheck source=bootstrap-inventory-emit-default.sh
#   source "$(dirname "$0")/bootstrap-inventory-emit-default.sh"
#   bootstrap_resolve_inventory_emit_driver "${INVENTORY_EMIT_DRIVER}"
#
# Override: BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER=0|1
# Bisect thin emit TU: BOOTSTRAP_M3_EMIT_HELPER_TU=1
bootstrap_resolve_inventory_emit_driver() {
  local driver_path="${1:-}"
  if [[ -n "${BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER+x}" ]]; then
    USE_INVENTORY_EMIT_DRIVER="${BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER}"
  elif [[ "${BOOTSTRAP_M3_EMIT_HELPER_TU:-0}" == "1" ]]; then
    USE_INVENTORY_EMIT_DRIVER=0
  elif [[ -n "${driver_path}" && -f "${driver_path}" ]]; then
    USE_INVENTORY_EMIT_DRIVER=1
  else
    USE_INVENTORY_EMIT_DRIVER=0
  fi
}
