#!/usr/bin/env bash
# M4 inventory-scale gen-1→gen-2 probe (#2770): same ladder as bootstrap-loop-probe plus
# compiler_lib_spine_smoke native emit (717 units, #2664).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
exec env \
  BOOTSTRAP_M4_GEN1_COMPILE_FULL_SPINE=1 \
  BOOTSTRAP_M4_LINK_COMPILE_DRIVER="${BOOTSTRAP_M4_LINK_COMPILE_DRIVER:-1}" \
  BOOTSTRAP_M4_COMPILE_DRIVER_REAL_LOWERING="${BOOTSTRAP_M4_COMPILE_DRIVER_REAL_LOWERING:-1}" \
  BOOTSTRAP_M4_RUNTIME_COMPILE="${BOOTSTRAP_M4_RUNTIME_COMPILE:-1}" \
  "${ROOT}/script/bootstrap-loop-probe.sh" "$@"
