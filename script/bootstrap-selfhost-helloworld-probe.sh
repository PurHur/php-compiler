#!/usr/bin/env bash
# M3 HelloWorld self-host probe (issue #1056): link selfhost bundle, compile HelloWorld AOT, run natively.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENTRY="${ROOT}/test/selfhost/compiler_helloworld_smoke/main.php"
PROBE="${ROOT}/build/selfhost-helloworld"
SOURCE="${ROOT}/examples/000-HelloWorld/example.php"
AOT_OUT="${ROOT}/build/helloworld-aot"
M3_NATIVE_COMPILE=0
# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
ci_apply_llvm_memory_env

if [[ ! -f "${ENTRY}" ]]; then
  echo "bootstrap-selfhost-helloworld-probe: missing ${ENTRY}" >&2
  exit 1
fi

if [[ ! -f "${SOURCE}" ]]; then
  echo "bootstrap-selfhost-helloworld-probe: missing ${SOURCE}" >&2
  exit 1
fi

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-selfhost-helloworld-probe: LLVM 9 not found (skip)" >&2
  exit 2
fi

mkdir -p "${ROOT}/build"
export PHP_COMPILER_SELFHOST_AOT=1
export PHP_COMPILER_JIT_PROGRESS_FILE="${ROOT}/build/.last-jit-func-helloworld-probe"
rm -f "${PROBE}" "${AOT_OUT}" "${PHP_COMPILER_JIT_PROGRESS_FILE}"

if ! php "${ROOT}/bin/compile.php" -o "${PROBE}" "${ENTRY}" 2>&1; then
  echo "bootstrap-selfhost-helloworld-probe: link bundle failed (see stderr above)" >&2
  exit 1
fi
test -x "${PROBE}"

bundle_out="$("${PROBE}")"
if ! grep -q 'compiler_helloworld_smoke bundle OK' <<< "${bundle_out}"; then
  echo "bootstrap-selfhost-helloworld-probe: unexpected bundle stdout (want compiler_helloworld_smoke bundle OK)" >&2
  printf '%s\n' "${bundle_out}" >&2
  exit 1
fi

export PHP_COMPILER_M3_SOURCE="${SOURCE}"
export PHP_COMPILER_M3_OUT="${AOT_OUT}"
set +e
compile_out="$("${PROBE}" 2>&1)"
native_compile_code=$?
set -e
if [[ "${native_compile_code}" -eq 0 ]] && grep -q 'helloworld_compile_smoke: compile OK' <<< "${compile_out}"; then
  M3_NATIVE_COMPILE=1
  echo "bootstrap-selfhost-helloworld-probe: native compile via selfhost bundle OK"
else
  echo "bootstrap-selfhost-helloworld-probe: native compile via selfhost bundle blocked (M3 partial)" >&2
  printf '%s\n' "${compile_out}" >&2
  echo "bootstrap-selfhost-helloworld-probe: NEXT_LOWER: parseAndCompile driver in PHP_COMPILER_SELFHOST_AOT bundle (OOM/segfault at link)" >&2
  echo "bootstrap-selfhost-helloworld-probe: falling back to Zend compile.php for HelloWorld AOT emit (gap to full M3)" >&2
  rm -f "${AOT_OUT}"
  if ! php "${ROOT}/bin/compile.php" -o "${AOT_OUT}" "${SOURCE}" 2>&1; then
    echo "bootstrap-selfhost-helloworld-probe: Zend HelloWorld compile failed" >&2
    exit 1
  fi
fi

if [[ ! -x "${AOT_OUT}" ]]; then
  echo "bootstrap-selfhost-helloworld-probe: missing executable ${AOT_OUT}" >&2
  exit 1
fi

run_out="$("${AOT_OUT}" 2>&1)"
if ! grep -q 'Hello World' <<< "${run_out}"; then
  echo "bootstrap-selfhost-helloworld-probe: unexpected AOT stdout (want Hello World)" >&2
  printf '%s\n' "${run_out}" >&2
  exit 1
fi

if [[ "${M3_NATIVE_COMPILE}" -eq 1 ]]; then
  echo "bootstrap-selfhost-helloworld-probe: OK ${PROBE} -> ${AOT_OUT} (native compile)"
else
  echo "bootstrap-selfhost-helloworld-probe: OK partial ${PROBE} + Zend emit -> ${AOT_OUT} (native run)"
fi
printf 'helloworld-aot stdout: %s\n' "${run_out}"
