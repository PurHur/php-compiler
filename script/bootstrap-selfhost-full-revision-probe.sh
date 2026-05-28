#!/usr/bin/env bash
# M4 full-revision probe (#2880): gen-2 argv driver native-compiles bin/compile.php → gen-3
# that compiles a bootstrap fixture via argv (no PHP_COMPILER_M3_* on the compile step).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "${ROOT}"

# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
ci_apply_llvm_memory_env

GEN2="${ROOT}/build/bin-compile-aot"
GEN3="${ROOT}/build/bootstrap-full-revision-gen3-compile"
FIXTURE="${ROOT}/test/bootstrap-aot/compiler_smoke_standalone.php"
FIXTURE_AOT="${ROOT}/build/bootstrap-full-revision-gen3-smoke-aot"

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-selfhost-full-revision-probe: LLVM 9 not found (skip)" >&2
  exit 2
fi

if [[ ! -f "${FIXTURE}" ]]; then
  echo "bootstrap-selfhost-full-revision-probe: missing ${FIXTURE}" >&2
  exit 1
fi

mkdir -p "${ROOT}/build"

if [[ ! -x "${GEN2}" ]]; then
  echo "bootstrap-selfhost-full-revision-probe: building gen-2 argv driver (driver smoke)" >&2
  if ! BOOTSTRAP_M5_DRIVER_SMOKE=1 ./script/bootstrap-selfhost-driver-smoke.sh >/dev/null; then
    echo "bootstrap-selfhost-full-revision-probe: driver smoke failed (need ${GEN2})" >&2
    exit 1
  fi
fi

if [[ ! -x "${GEN2}" ]]; then
  echo "bootstrap-selfhost-full-revision-probe: missing gen-2 driver ${GEN2}" >&2
  exit 1
fi

rm -f "${GEN3}" "${FIXTURE_AOT}"
set +e
gen3_link_out="$(
  env -u PHP_COMPILER_M3_SOURCE -u PHP_COMPILER_M3_OUT \
    "${GEN2}" -o "${GEN3}" "${ROOT}/bin/compile.php" 2>&1
)"
gen3_link_code=$?
set -e
printf '%s\n' "${gen3_link_out}"

if [[ "${gen3_link_code}" -ne 0 ]]; then
  echo "bootstrap-selfhost-full-revision-probe: gen-2 compile bin/compile.php failed (exit ${gen3_link_code})" >&2
  exit 1
fi
if ! grep -qE 'compile_smoke_m3_emit: compile OK|helloworld_compile_smoke: compile OK' <<< "${gen3_link_out}"; then
  echo "bootstrap-selfhost-full-revision-probe: expected compile OK from gen-2 emit" >&2
  exit 1
fi
if [[ ! -x "${GEN3}" ]]; then
  echo "bootstrap-selfhost-full-revision-probe: missing gen-3 driver ${GEN3}" >&2
  exit 1
fi

set +e
gen3_emit_out="$(
  env -u PHP_COMPILER_M3_SOURCE -u PHP_COMPILER_M3_OUT \
    "${GEN3}" -o "${FIXTURE_AOT}" "${FIXTURE}" 2>&1
)"
gen3_emit_code=$?
set -e
printf '%s\n' "${gen3_emit_out}"

if [[ "${gen3_emit_code}" -ne 0 ]]; then
  echo "bootstrap-selfhost-full-revision-probe: gen-3 argv emit failed (exit ${gen3_emit_code})" >&2
  exit 1
fi
if ! grep -qE 'compile_smoke_m3_emit: compile OK|helloworld_compile_smoke: compile OK' <<< "${gen3_emit_out}"; then
  echo "bootstrap-selfhost-full-revision-probe: gen-3 emit missing compile OK line" >&2
  exit 1
fi
if [[ ! -x "${FIXTURE_AOT}" ]]; then
  echo "bootstrap-selfhost-full-revision-probe: missing ${FIXTURE_AOT}" >&2
  exit 1
fi

run_out="$("${FIXTURE_AOT}" 2>&1)"
if ! grep -qx 'compiler smoke' <<< "${run_out}"; then
  echo "bootstrap-selfhost-full-revision-probe: unexpected fixture stdout (want compiler smoke)" >&2
  printf '%s\n' "${run_out}" >&2
  exit 1
fi

echo "bootstrap-selfhost-full-revision-probe: OK gen-2=${GEN2} gen-3=${GEN3} emit_path=native (argv full revision #2880)"
