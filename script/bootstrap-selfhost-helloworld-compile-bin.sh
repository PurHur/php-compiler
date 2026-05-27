#!/usr/bin/env bash
# M5 repro: native compile via helloworld compile_driver (not emit-TU — #2681).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENTRY="${ROOT}/test/bootstrap-aot/helloworld_compile_m3_emit_native_entry.php"
OUT="${ROOT}/build/selfhost-helloworld-compile"
SOURCE="${PHP_COMPILER_M3_SOURCE:-${ROOT}/examples/000-HelloWorld/example.php}"
AOT_OUT="${PHP_COMPILER_M3_OUT:-${ROOT}/build/helloworld-compile-bin-aot}"
# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
ci_apply_llvm_memory_env

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-selfhost-helloworld-compile-bin: LLVM 9 not found (skip)" >&2
  exit 2
fi

if [[ ! -f "${ENTRY}" ]]; then
  echo "bootstrap-selfhost-helloworld-compile-bin: missing ${ENTRY}" >&2
  exit 1
fi

mkdir -p "${ROOT}/build"
export PHP_COMPILER_SELFHOST_AOT=1
export PHP_COMPILER_M3_COMPILE_DRIVER=1
export PHP_COMPILER_EMIT_HELPER_LINK=1
export PHP_COMPILER_JIT_PROGRESS_FILE="${ROOT}/build/.last-jit-func-helloworld-compile-bin"
rm -f "${OUT}" "${AOT_OUT}" "${PHP_COMPILER_JIT_PROGRESS_FILE}"

set +e
php "${ROOT}/bin/compile.php" -o "${OUT}" "${ENTRY}" >/dev/null 2>&1
link_code=$?
set -e
if [[ ! -x "${OUT}" ]]; then
  echo "bootstrap-selfhost-helloworld-compile-bin: link failed (exit ${link_code})" >&2
  exit 1
fi
echo "bootstrap-selfhost-helloworld-compile-bin: link OK ${OUT}"

set +e
compile_out="$(
  env PHP_COMPILER_M3_SOURCE="${SOURCE}" \
    PHP_COMPILER_M3_OUT="${AOT_OUT}" \
    "${OUT}" 2>&1
)"
compile_code=$?
set -e
printf '%s\n' "${compile_out}"

if [[ "${compile_code}" -eq 0 ]] && grep -q 'helloworld_compile_smoke: compile OK' <<< "${compile_out}"; then
  echo "bootstrap-selfhost-helloworld-compile-bin: OK ${OUT} -> ${AOT_OUT}"
  exit 0
fi

if grep -q 'helloworld_compile_smoke:' <<< "${compile_out}"; then
  echo "bootstrap-selfhost-helloworld-compile-bin: compile blocked (honest helloworld_compile_smoke prefix — not emit-TU)" >&2
  exit 1
fi

echo "bootstrap-selfhost-helloworld-compile-bin: unexpected output (want helloworld_compile_smoke: prefix)" >&2
exit 1
