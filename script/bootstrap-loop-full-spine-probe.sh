#!/usr/bin/env bash
# M4 inventory-scale gen-1 emit probe (issue #2770 / #2664).
#
# Runs the full bootstrap-loop ladder (M2 spine + M3 HelloWorld strict + gen-1 slice)
# and then gen-1 native emit of compiler_lib_spine_smoke (717 require_once units).
#
# Usage:
#   make bootstrap-loop-full-spine-probe
#   ./script/bootstrap-loop-full-spine-probe.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"

export BOOTSTRAP_M4_LINK_COMPILE_DRIVER="${BOOTSTRAP_M4_LINK_COMPILE_DRIVER:-1}"
export BOOTSTRAP_M4_COMPILE_DRIVER_REAL_LOWERING="${BOOTSTRAP_M4_COMPILE_DRIVER_REAL_LOWERING:-1}"
export BOOTSTRAP_M4_RUNTIME_COMPILE="${BOOTSTRAP_M4_RUNTIME_COMPILE:-1}"
export BOOTSTRAP_M4_GEN1_COMPILE_FULL_SPINE=1

exec bash "${ROOT}/script/bootstrap-loop-probe.sh"
