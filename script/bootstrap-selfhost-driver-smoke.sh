#!/usr/bin/env bash
# M5 bootstrap driver smoke (#1521, #2769): native helloworld compile driver compiles + runs a
# bootstrap fixture without Zend on the compile step (gen-1 emit → gen-2 run).
#
# Stage 0: Zend links build/selfhost-helloworld-compile (M3 compile-driver TU).
# Stage 1: Native driver emits test/bootstrap-aot/compiler_smoke_standalone.php → gen-2 binary.
# Stage 2: Run gen-2; expect "compiler smoke" (same as M4 loop gen-2 slice).
# Stage 3–4: build bin/compile.php argv driver (build/bin-compile-aot) and native emit probe.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# shellcheck source=php-env.sh
source "${ROOT}/script/php-env.sh"
ci_apply_llvm_memory_env

HELLOWORLD_DRIVER="${ROOT}/build/selfhost-helloworld-compile"
BIN_COMPILE_DRIVER="${ROOT}/build/bin-compile-aot"
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

if [[ ! -x "${HELLOWORLD_DRIVER}" ]]; then
  echo "bootstrap-selfhost-driver-smoke: missing helloworld compile driver ${HELLOWORLD_DRIVER}" >&2
  exit 1
fi

echo "bootstrap-selfhost-driver-smoke: stage 1 — native gen-2 compile (no Zend on compile)"
set +e
compile_out="$(
  env PHP_COMPILER_M3_COMPILE_MODE=compile \
    PHP_COMPILER_M3_RUNTIME_COMPILE=1 \
    PHP_COMPILER_M3_SOURCE="${SOURCE}" \
    PHP_COMPILER_M3_OUT="${GEN2_OUT}" \
    "${HELLOWORLD_DRIVER}" 2>&1
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

echo "bootstrap-selfhost-driver-smoke: stage 3 — build native bin/compile.php argv driver (no Zend on emit)"
export PHP_COMPILER_M3_SOURCE="${ROOT}/bin/compile.php"
export PHP_COMPILER_M3_OUT="${BIN_COMPILE_DRIVER}"
if ! ./script/bootstrap-selfhost-helloworld-compile-bin.sh >/dev/null; then
  echo "bootstrap-selfhost-driver-smoke: native bin/compile.php helper link failed" >&2
  exit 1
fi
if [[ ! -x "${BIN_COMPILE_DRIVER}" ]]; then
  echo "bootstrap-selfhost-driver-smoke: missing compiled bin/compile.php helper ${BIN_COMPILE_DRIVER}" >&2
  exit 1
fi

echo "bootstrap-selfhost-driver-smoke: stage 4 — compiled argv driver native emit (no Zend)"
EMIT_PROBE="${ROOT}/test/selfhost/compiler_unit_probe/compiler_unit_probe_compile.php"
EMIT_OUT="${ROOT}/build/selfhost-driver-smoke-emit-aot"
rm -f "${EMIT_OUT}"
set +e
emit_out="$(
  env -u PHP_COMPILER_M3_SOURCE -u PHP_COMPILER_M3_OUT \
    "${BIN_COMPILE_DRIVER}" -o "${EMIT_OUT}" "${EMIT_PROBE}" 2>&1
)"
emit_code=$?
set -e
printf '%s\n' "${emit_out}"
if [[ "${emit_code}" -ne 0 ]]; then
  echo "bootstrap-selfhost-driver-smoke: compiled driver emit failed (exit ${emit_code})" >&2
  exit 1
fi
if [[ ! -x "${EMIT_OUT}" ]]; then
  echo "bootstrap-selfhost-driver-smoke: missing ${EMIT_OUT}" >&2
  exit 1
fi
if grep -qE 'compile_smoke_m3_emit:' <<< "${emit_out}"; then
  echo "bootstrap-selfhost-driver-smoke: compiled argv driver still using compile_smoke_m3_emit helper (want inventory Compiler path)" >&2
  exit 1
fi

echo "bootstrap-selfhost-driver-smoke: stage 5 — gen-3 recompile bin/compile.php + argv emit (#2890)"
GEN3_DRIVER="${ROOT}/build/bootstrap-driver-smoke-gen3-compile"
GEN3_SMOKE="${ROOT}/build/bootstrap-driver-smoke-gen3-smoke"
rm -f "${GEN3_DRIVER}" "${GEN3_SMOKE}"
set +e
gen3_link_out="$(
  env -u PHP_COMPILER_M3_SOURCE -u PHP_COMPILER_M3_OUT \
    "${BIN_COMPILE_DRIVER}" -o "${GEN3_DRIVER}" "${ROOT}/bin/compile.php" 2>&1
)"
gen3_link_code=$?
set -e
printf '%s\n' "${gen3_link_out}"
if [[ "${gen3_link_code}" -ne 0 ]]; then
  echo "bootstrap-selfhost-driver-smoke: gen-3 link bin/compile.php failed (exit ${gen3_link_code})" >&2
  exit 1
fi
if ! grep -qE 'helloworld_compile_smoke: compile OK|compile_smoke_m3_emit: compile OK' <<< "${gen3_link_out}"; then
  echo "bootstrap-selfhost-driver-smoke: gen-3 link missing compile OK line" >&2
  exit 1
fi
if [[ ! -x "${GEN3_DRIVER}" ]]; then
  echo "bootstrap-selfhost-driver-smoke: missing gen-3 driver ${GEN3_DRIVER}" >&2
  exit 1
fi
set +e
gen3_emit_out="$(
  env -u PHP_COMPILER_M3_SOURCE -u PHP_COMPILER_M3_OUT \
    "${GEN3_DRIVER}" -o "${GEN3_SMOKE}" "${EMIT_PROBE}" 2>&1
)"
gen3_emit_code=$?
set -e
printf '%s\n' "${gen3_emit_out}"
if [[ "${gen3_emit_code}" -ne 0 ]]; then
  echo "bootstrap-selfhost-driver-smoke: gen-3 argv emit failed (exit ${gen3_emit_code})" >&2
  exit 1
fi
if ! grep -qE 'helloworld_compile_smoke: compile OK|compile_smoke_m3_emit: compile OK' <<< "${gen3_emit_out}"; then
  echo "bootstrap-selfhost-driver-smoke: gen-3 emit missing compile OK line (silent success guard #2890)" >&2
  exit 1
fi
if [[ ! -x "${GEN3_SMOKE}" ]]; then
  echo "bootstrap-selfhost-driver-smoke: gen-3 missing output ${GEN3_SMOKE} (silent success guard #2890)" >&2
  exit 1
fi
set +e
gen3_run_out="$("${GEN3_SMOKE}" 2>&1)"
gen3_run_code=$?
set -e
printf '%s\n' "${gen3_run_out}"
if [[ "${gen3_run_code}" -ne 0 ]]; then
  echo "bootstrap-selfhost-driver-smoke: gen-3 smoke run failed (exit ${gen3_run_code})" >&2
  exit 1
fi
if ! grep -qx "${EXPECTED_STDOUT}" <<< "${gen3_run_out}"; then
  echo "bootstrap-selfhost-driver-smoke: gen-3 smoke run unexpected stdout (want ${EXPECTED_STDOUT})" >&2
  exit 1
fi

echo "bootstrap-selfhost-driver-smoke: emit_path=native (gen-2 compile + run + gen-3 argv)"
echo "bootstrap-selfhost-driver-smoke: OK ${HELLOWORLD_DRIVER} -> ${GEN2_OUT} (argv driver ${BIN_COMPILE_DRIVER}, gen-3 ${GEN3_DRIVER})"
