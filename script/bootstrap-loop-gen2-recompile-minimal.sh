#!/usr/bin/env bash
# M4/M5: native driver recompiles full compiler spine (717/717) — issue #1498, #2697.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "${ROOT}"

# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
ci_apply_llvm_memory_env

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-loop-gen2-recompile-minimal: LLVM 9 not found (skip)" >&2
  exit 2
fi

DRIVER="${ROOT}/build/bin-compile-aot"
GEN3="${ROOT}/build/bootstrap-loop-gen3-full-spine"
SOURCE="${ROOT}/test/selfhost/compiler_lib_spine_smoke/main.php"

if [[ ! -x "${DRIVER}" ]]; then
  export PHP_COMPILER_M3_SOURCE="${ROOT}/bin/compile.php"
  export PHP_COMPILER_M3_OUT="${DRIVER}"
  "${ROOT}/script/bootstrap-selfhost-helloworld-compile-bin.sh" >/dev/null
fi
if [[ ! -x "${DRIVER}" ]]; then
  echo "bootstrap-loop-gen2-recompile-minimal: missing native driver ${DRIVER}" >&2
  exit 1
fi

rm -f "${GEN3}"
set +e
out="$(
  env PHP_COMPILER_M3_SOURCE="${SOURCE}" \
    PHP_COMPILER_M3_OUT="${GEN3}" \
    "${DRIVER}" 2>&1
)"
code=$?
set -e
printf '%s\n' "${out}"
if [[ "${code}" -ne 0 ]] || ! grep -qE 'compile_smoke_m3_emit: compile OK|helloworld_compile_smoke: compile OK' <<< "${out}"; then
  echo "bootstrap-loop-gen2-recompile-minimal: gen-2 native spine compile failed (exit ${code})" >&2
  exit 1
fi
if [[ ! -x "${GEN3}" ]]; then
  echo "bootstrap-loop-gen2-recompile-minimal: missing ${GEN3}" >&2
  exit 1
fi

run_out="$("${GEN3}" 2>&1)"
if ! grep -q 'compiler_lib_spine_smoke bundle OK' <<< "${run_out}"; then
  echo "bootstrap-loop-gen2-recompile-minimal: unexpected gen-3 stdout" >&2
  printf '%s\n' "${run_out}" >&2
  exit 1
fi

echo "bootstrap-loop-gen2-recompile-minimal: OK gen-2=${DRIVER} gen-3=${GEN3} (717/717 spine)"
