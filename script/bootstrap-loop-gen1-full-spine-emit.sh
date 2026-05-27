#!/usr/bin/env bash
# M4: opt-in proof that gen-1 can native-emit the full compiler_lib_spine_smoke bundle (#2664).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "${ROOT}"

# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
ci_apply_llvm_memory_env

export BOOTSTRAP_M4_GEN1_COMPILE_FULL_SPINE=1
export BOOTSTRAP_M4_GEN2_STRICT=1
: "${BOOTSTRAP_M4_LINK_COMPILE_DRIVER:=1}"
: "${BOOTSTRAP_M4_COMPILE_DRIVER_REAL_LOWERING:=1}"
: "${BOOTSTRAP_M4_RUNTIME_COMPILE:=1}"

echo "=== M4 gen-1 full spine native emit (#2664) ==="
echo "target: test/selfhost/compiler_lib_spine_smoke/main.php (717 units)"
echo ""

bash "${ROOT}/script/bootstrap-loop-gen1-link.sh"
