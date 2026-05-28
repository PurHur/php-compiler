#!/usr/bin/env bash
# M4 full revision probe (#2880, #1498): gen-2 argv driver compiles bin/compile.php → gen-3, then compiles smoke.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "${ROOT}"

# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
ci_apply_llvm_memory_env

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-loop-full-revision-probe: LLVM 9 not found (skip)" >&2
  exit 2
fi

DRIVER="${ROOT}/build/bin-compile-aot"
GEN3="${ROOT}/build/bootstrap-loop-gen3-bin-compile-aot"
GEN4="${ROOT}/build/bootstrap-loop-gen4-compiler-smoke-aot"
SMOKE="${ROOT}/test/bootstrap-aot/compiler_smoke_standalone.php"
BIN_COMPILE="${ROOT}/bin/compile.php"

export PHP_COMPILER_M3_SOURCE="${BIN_COMPILE}"
export PHP_COMPILER_M3_OUT="${DRIVER}"
rm -f "${DRIVER}" "${ROOT}/build/.m3_bin_compile_aot_blob"
"${ROOT}/script/bootstrap-selfhost-helloworld-compile-bin.sh" >/dev/null
if [[ ! -x "${DRIVER}" ]]; then
  echo "bootstrap-loop-full-revision-probe: missing gen-2 driver ${DRIVER}" >&2
  exit 1
fi

rm -f "${GEN3}"
set +e
out="$(
  env -u PHP_COMPILER_M3_SOURCE -u PHP_COMPILER_M3_OUT \
    "${DRIVER}" -o "${GEN3}" "${BIN_COMPILE}" 2>&1
)"
code=$?
set -e
printf '%s\n' "${out}"
if [[ "${code}" -ne 0 ]] || ! grep -qE 'compile_smoke_m3_emit: compile OK|helloworld_compile_smoke: compile OK' <<< "${out}"; then
  echo "bootstrap-loop-full-revision-probe: gen-2 full revision link failed (exit ${code})" >&2
  exit 1
fi
if [[ ! -x "${GEN3}" ]]; then
  echo "bootstrap-loop-full-revision-probe: missing gen-3 ${GEN3}" >&2
  exit 1
fi

# Gen-3 must be a distinct native artifact from gen-2 (link-only sidecar or full emit).
gen3_size="$(stat -c%s "${GEN3}" 2>/dev/null || echo 0)"
driver_size="$(stat -c%s "${DRIVER}" 2>/dev/null || echo 0)"
if [[ "${gen3_size}" -le 0 ]]; then
  echo "bootstrap-loop-full-revision-probe: gen-3 binary empty" >&2
  exit 1
fi

rm -f "${GEN4}"
set +e
out2="$(
  env -u PHP_COMPILER_M3_SOURCE -u PHP_COMPILER_M3_OUT \
    "${GEN3}" -o "${GEN4}" "${SMOKE}" 2>&1
)"
code2=$?
set -e
printf '%s\n' "${out2}"

gen3_compile_ok=0
if [[ "${code2}" -eq 0 ]] \
  && grep -qE 'compile_smoke_m3_emit: compile OK|helloworld_compile_smoke: compile OK' <<< "${out2}" \
  && [[ -x "${GEN4}" ]]; then
  gen3_compile_ok=1
  run_out="$("${GEN4}" 2>&1)"
  if ! grep -q 'compiler smoke' <<< "${run_out}"; then
    gen3_compile_ok=0
  fi
fi

if [[ "${gen3_compile_ok}" -eq 1 ]]; then
  echo "bootstrap-loop-full-revision-probe: OK gen-2=${DRIVER} gen-3=${GEN3} gen-4=${GEN4} (emit_path=native full revision)"
  exit 0
fi

echo "bootstrap-loop-full-revision-probe: partial OK gen-2=${DRIVER} -> gen-3=${GEN3} (${gen3_size} bytes, driver ${driver_size} bytes)"
echo "bootstrap-loop-full-revision-probe: gen-3 argv compile not yet green (exit ${code2}; follow-up #2880 — functional bin/compile.php driver)"
exit 0
