#!/usr/bin/env bash
# M4 full-revision probe (#2880): gen-2 argv driver native-compiles bin/compile.php → gen-3
# that compiles a bootstrap fixture via argv (no PHP_COMPILER_M3_* on the compile step).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "${ROOT}"

# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
# shellcheck source=bootstrap-ensure-inventory-argv-driver.sh
source "$(dirname "$0")/bootstrap-ensure-inventory-argv-driver.sh"
ci_apply_llvm_memory_env

GEN2_INVENTORY="${ROOT}/build/bin-compile-aot-inventory"
GEN3="${ROOT}/build/bootstrap-full-revision-gen3-compile"
FIXTURE="${ROOT}/test/selfhost/compiler_unit_probe/compiler_unit_probe_compile.php"
FIXTURE_AOT="${ROOT}/build/bootstrap-full-revision-gen3-compiler-unit-probe-aot"

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-selfhost-full-revision-probe: LLVM 9 not found (skip)" >&2
  exit 2
fi

if [[ ! -f "${FIXTURE}" ]]; then
  echo "bootstrap-selfhost-full-revision-probe: missing ${FIXTURE}" >&2
  exit 1
fi

mkdir -p "${ROOT}/build"

if ! bootstrap_ensure_inventory_argv_driver_ssot "${GEN2_INVENTORY}"; then
  echo "bootstrap-selfhost-full-revision-probe: failed to ensure gen-2 inventory argv driver ${GEN2_INVENTORY}" >&2
  exit 1
fi

rm -f "${GEN3}" "${FIXTURE_AOT}"
set +e
gen3_link_out="$(
  env -u PHP_COMPILER_M3_SOURCE -u PHP_COMPILER_M3_OUT \
    PHP_COMPILER_SELFHOST_AOT=1 \
    PHP_COMPILER_M3_COMPILE_DRIVER=1 \
    PHP_COMPILER_M4_BIN_COMPILE_DRIVER=1 \
    PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1 \
    BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER=1 \
    "${GEN2_INVENTORY}" -o "${GEN3}" "${ROOT}/bin/compile.php" 2>&1
)"
gen3_link_code=$?
set -e
printf '%s\n' "${gen3_link_out}"

if [[ "${gen3_link_code}" -ne 0 ]]; then
  echo "bootstrap-selfhost-full-revision-probe: gen-2 compile bin/compile.php failed (exit ${gen3_link_code})" >&2
  exit 1
fi
if ! grep -qE 'helloworld_compile_smoke: compile OK|compile_smoke_m3_emit: compile OK' <<< "${gen3_link_out}"; then
  echo "bootstrap-selfhost-full-revision-probe: gen-2 compile bin/compile.php missing compile OK line (#3046)" >&2
  exit 1
fi
if [[ ! -x "${GEN3}" ]]; then
  echo "bootstrap-selfhost-full-revision-probe: missing gen-3 driver ${GEN3}" >&2
  exit 1
fi
gen3_bytes="$(wc -c <"${GEN3}" 2>/dev/null || echo 0)"
if [[ "${gen3_bytes}" -lt 350000 ]]; then
  echo "bootstrap-selfhost-full-revision-probe: gen-3 driver looks like link-time sidecar stub (${gen3_bytes} bytes; want inventory argv driver #3012)" >&2
  exit 1
fi
if grep -qE 'compile_smoke_m3_emit:' <<< "${gen3_link_out}"; then
  echo "bootstrap-selfhost-full-revision-probe: unexpected compile_smoke_m3_emit log while building gen-3 (want inventory Compiler path)" >&2
  exit 1
fi

set +e
gen3_emit_out="$(
  env -u PHP_COMPILER_M3_SOURCE -u PHP_COMPILER_M3_OUT \
    PHP_COMPILER_SELFHOST_AOT=1 \
    PHP_COMPILER_M3_COMPILE_DRIVER=1 \
    PHP_COMPILER_M4_BIN_COMPILE_DRIVER=1 \
    PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1 \
    BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER=1 \
    "${GEN3}" -o "${FIXTURE_AOT}" "${FIXTURE}" 2>&1
)"
gen3_emit_code=$?
set -e
printf '%s\n' "${gen3_emit_out}"

if [[ "${gen3_emit_code}" -ne 0 ]]; then
  echo "bootstrap-selfhost-full-revision-probe: gen-3 argv emit failed (exit ${gen3_emit_code})" >&2
  exit 1
fi
if [[ ! -x "${FIXTURE_AOT}" ]]; then
  echo "bootstrap-selfhost-full-revision-probe: missing ${FIXTURE_AOT}" >&2
  exit 1
fi
if grep -qE 'compile_smoke_m3_emit:' <<< "${gen3_emit_out}"; then
  echo "bootstrap-selfhost-full-revision-probe: gen-3 argv emit still using compile_smoke_m3_emit helper (want inventory Compiler path)" >&2
  exit 1
fi

run_out="$("${FIXTURE_AOT}" 2>&1 || true)"

echo "bootstrap-selfhost-full-revision-probe: OK gen-2=${GEN2_INVENTORY} gen-3=${GEN3} emit_path=native (argv full revision #2880)"
