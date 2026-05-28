#!/usr/bin/env bash
# M4: gen-2 native driver compiles bin/compile.php → gen-3 argv compile driver (#2880, #1498).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "${ROOT}"

# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
ci_apply_llvm_memory_env

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-loop-gen2-recompile-bin-compile: LLVM 9 not found (skip)" >&2
  exit 2
fi

DRIVER="${ROOT}/build/bin-compile-aot"
GEN3="${ROOT}/build/bootstrap-loop-gen3-bin-compile-aot"
SOURCE="${ROOT}/bin/compile.php"
SMOKE="${ROOT}/test/bootstrap-aot/compiler_smoke_standalone.php"
SMOKE_OUT="${ROOT}/build/bootstrap-loop-gen3-bin-compile-smoke"

if [[ ! -x "${DRIVER}" ]]; then
  export PHP_COMPILER_M3_SOURCE="${SOURCE}"
  export PHP_COMPILER_M3_OUT="${DRIVER}"
  "${ROOT}/script/bootstrap-selfhost-helloworld-compile-bin.sh" >/dev/null
fi
if [[ ! -x "${DRIVER}" ]]; then
  echo "bootstrap-loop-gen2-recompile-bin-compile: missing native driver ${DRIVER}" >&2
  exit 1
fi

rm -f "${GEN3}" "${SMOKE_OUT}"
set +e
out="$(
  env -u PHP_COMPILER_M3_SOURCE -u PHP_COMPILER_M3_OUT \
    "${DRIVER}" -o "${GEN3}" "${SOURCE}" 2>&1
)"
code=$?
set -e
printf '%s\n' "${out}"
if [[ "${code}" -ne 0 ]] || ! grep -qE 'compile_smoke_m3_emit: compile OK|helloworld_compile_smoke: compile OK' <<< "${out}"; then
  echo "bootstrap-loop-gen2-recompile-bin-compile: gen-2 native bin/compile.php compile failed (exit ${code})" >&2
  exit 1
fi
if [[ ! -x "${GEN3}" ]]; then
  echo "bootstrap-loop-gen2-recompile-bin-compile: missing ${GEN3}" >&2
  exit 1
fi

set +e
smoke_out="$(
  env -u PHP_COMPILER_M3_SOURCE -u PHP_COMPILER_M3_OUT \
    "${GEN3}" -o "${SMOKE_OUT}" "${SMOKE}" 2>&1
)"
smoke_code=$?
set -e
printf '%s\n' "${smoke_out}"
if [[ "${smoke_code}" -ne 0 ]] || ! grep -qE 'compile_smoke_m3_emit: compile OK|helloworld_compile_smoke: compile OK' <<< "${smoke_out}"; then
  echo "bootstrap-loop-gen2-recompile-bin-compile: gen-3 argv compile failed (exit ${smoke_code})" >&2
  exit 1
fi
if [[ ! -x "${SMOKE_OUT}" ]]; then
  echo "bootstrap-loop-gen2-recompile-bin-compile: missing ${SMOKE_OUT}" >&2
  exit 1
fi

run_out="$("${SMOKE_OUT}" 2>&1)"
if ! grep -q 'compiler smoke' <<< "${run_out}"; then
  echo "bootstrap-loop-gen2-recompile-bin-compile: unexpected gen-3 smoke stdout" >&2
  printf '%s\n' "${run_out}" >&2
  exit 1
fi

echo "bootstrap-loop-gen2-recompile-bin-compile: OK gen-2=${DRIVER} gen-3=${GEN3} (argv bin/compile.php, emit_path=native)"
