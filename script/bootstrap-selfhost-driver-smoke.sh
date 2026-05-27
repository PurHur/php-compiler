#!/usr/bin/env bash
# M5 bootstrap driver smoke (#1521): native helloworld compile driver compiles + runs a
# bootstrap fixture without Zend on the compile step (gen-1 emit → gen-2 run).
#
# Stage 0: Zend links build/selfhost-helloworld-compile (M3 compile-driver TU).
# Stage 1: Native driver emits test/bootstrap-aot/compiler_smoke_standalone.php → gen-2 binary.
# Stage 2: Run gen-2; expect "compiler smoke" (same as M4 loop gen-2 slice).
#
# Optional slice: compiled bin/compile.php lint on a tiny fixture (no Zend) when present.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# shellcheck source=php-env.sh
source "${ROOT}/script/php-env.sh"
ci_apply_llvm_memory_env

COMPILED_COMPILE="${ROOT}/build/bin-compile-aot"
SOURCE="${ROOT}/test/bootstrap-aot/compiler_smoke_standalone.php"
GEN2_OUT="${ROOT}/build/selfhost-driver-smoke-gen2"
EXPECTED_STDOUT="compiler smoke"

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-selfhost-driver-smoke: LLVM 9 not found (skip)" >&2
  exit 2
fi

if [[ ! -f "${SOURCE}" ]]; then
  echo "bootstrap-selfhost-driver-smoke: missing ${SOURCE}" >&2
  exit 1
fi

mkdir -p "${ROOT}/build"
rm -f "${GEN2_OUT}"

echo "bootstrap-selfhost-driver-smoke: stage 0 — link native M3 compile driver (Zend link only)"
# Build the HelloWorld compile driver first; do not point this script at bin/compile.php
# (that path builds the argv -o helper instead of build/selfhost-helloworld-compile).
if ! ./script/bootstrap-selfhost-helloworld-compile-bin.sh >/dev/null; then
  echo "bootstrap-selfhost-driver-smoke: helloworld compile-driver link failed" >&2
  exit 1
fi

if [[ ! -x "${COMPILED_COMPILE}" ]]; then
  echo "bootstrap-selfhost-driver-smoke: missing compiled bin/compile.php driver ${COMPILED_COMPILE}" >&2
  exit 1
fi

echo "bootstrap-selfhost-driver-smoke: stage 1 — native gen-2 compile (no Zend on compile)"
set +e
compile_out="$(
  env -u PHP_COMPILER_M3_COMPILE_MODE \
    PHP_COMPILER_M3_SOURCE="${SOURCE}" \
    PHP_COMPILER_M3_OUT="${GEN2_OUT}" \
    "${COMPILED_COMPILE}" 2>&1
)"
compile_code=$?
set -e
printf '%s\n' "${compile_out}"

if [[ "${compile_code}" -ne 0 ]]; then
  echo "bootstrap-selfhost-driver-smoke: native compile failed (exit ${compile_code})" >&2
  exit 1
fi

if ! grep -qE 'helloworld_compile_smoke: compile OK|compile_smoke_m3_emit: compile OK' <<< "${compile_out}"; then
  echo "bootstrap-selfhost-driver-smoke: expected compile OK line (helloworld_compile_smoke or compile_smoke_m3_emit)" >&2
  exit 1
fi

if [[ ! -x "${GEN2_OUT}" ]]; then
  echo "bootstrap-selfhost-driver-smoke: missing gen-2 binary ${GEN2_OUT}" >&2
  exit 1
fi

echo "bootstrap-selfhost-driver-smoke: stage 2 — run gen-2 binary"
set +e
run_out="$("${GEN2_OUT}" 2>&1)"
run_code=$?
set -e
printf '%s\n' "${run_out}"

if [[ "${run_code}" -ne 0 ]]; then
  echo "bootstrap-selfhost-driver-smoke: gen-2 run failed (exit ${run_code})" >&2
  exit 1
fi

if ! grep -qx "${EXPECTED_STDOUT}" <<< "${run_out}"; then
  echo "bootstrap-selfhost-driver-smoke: unexpected stdout (want ${EXPECTED_STDOUT})" >&2
  exit 1
fi

if [[ -x "${COMPILED_COMPILE}" ]]; then
  echo "bootstrap-selfhost-driver-smoke: stage 3 — compiled driver native emit (no Zend)"
  EMIT_PROBE="${ROOT}/test/bootstrap-aot/compiler_smoke_standalone.php"
  EMIT_OUT="${ROOT}/build/selfhost-driver-smoke-emit-aot"
  rm -f "${EMIT_OUT}"
else
  echo "bootstrap-selfhost-driver-smoke: stage 3 — build native bin/compile.php helper (argv -o) then emit (no Zend)"
  export PHP_COMPILER_M3_SOURCE="${ROOT}/bin/compile.php"
  export PHP_COMPILER_M3_OUT="${COMPILED_COMPILE}"
  if ! ./script/bootstrap-selfhost-helloworld-compile-bin.sh >/dev/null; then
    echo "bootstrap-selfhost-driver-smoke: native bin/compile.php helper link failed" >&2
    exit 1
  fi
  if [[ ! -x "${COMPILED_COMPILE}" ]]; then
    echo "bootstrap-selfhost-driver-smoke: missing compiled bin/compile.php helper ${COMPILED_COMPILE}" >&2
    exit 1
  fi
  echo "bootstrap-selfhost-driver-smoke: stage 4 — compiled driver native emit (no Zend)"
  EMIT_PROBE="${ROOT}/test/bootstrap-aot/compiler_smoke_standalone.php"
  EMIT_OUT="${ROOT}/build/selfhost-driver-smoke-emit-aot"
  rm -f "${EMIT_OUT}"
fi
  set +e
  emit_out="$(
    env PHP_COMPILER_M3_SOURCE="${EMIT_PROBE}" \
      PHP_COMPILER_M3_OUT="${EMIT_OUT}" \
      "${COMPILED_COMPILE}" 2>&1
  )"
  emit_code=$?
  set -e
  printf '%s\n' "${emit_out}"
  if [[ "${emit_code}" -ne 0 ]]; then
    echo "bootstrap-selfhost-driver-smoke: compiled driver emit failed (exit ${emit_code})" >&2
    exit 1
  fi
  if ! grep -qE 'helloworld_compile_smoke: compile OK|compile_smoke_m3_emit: compile OK' <<< "${emit_out}"; then
    echo "bootstrap-selfhost-driver-smoke: expected compile OK line from compiled driver" >&2
    exit 1
  fi
  if [[ ! -x "${EMIT_OUT}" ]]; then
    echo "bootstrap-selfhost-driver-smoke: missing ${EMIT_OUT}" >&2
    exit 1
  fi
 

echo "bootstrap-selfhost-driver-smoke: emit_path=native (gen-2 compile + run)"
echo "bootstrap-selfhost-driver-smoke: OK ${COMPILED_COMPILE} -> ${GEN2_OUT}"
