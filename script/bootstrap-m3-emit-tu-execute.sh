#!/usr/bin/env bash
# M3 emit-TU: native link, compile, and execute (issue #2444; fix tracked in #2442).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
EMIT_ENTRY="${ROOT}/test/bootstrap-aot/runtime_m3_emit_native_entry.php"
SOURCE="${ROOT}/test/bootstrap-aot/runtime_trivial_echo.php"
EMIT_HELPER="${ROOT}/build/m3-emit-tu-phpunit-helper"
AOT_OUT="${ROOT}/build/m3-emit-tu-phpunit-aot"

# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
ci_apply_llvm_memory_env

m3_exit_label() {
  local code=$1
  if [[ "${code}" -eq 139 ]]; then
    echo "segfault (emit-TU startup; see #2442)"
  elif [[ "${code}" -eq 137 ]]; then
    echo "SIGKILL (likely OOM during link)"
  elif [[ "${code}" -ne 0 ]]; then
    echo "exit ${code}"
  else
    echo "ok"
  fi
}

if [[ ! -f "${EMIT_ENTRY}" ]]; then
  echo "bootstrap-m3-emit-tu-execute: missing ${EMIT_ENTRY}" >&2
  exit 1
fi

if [[ ! -f "${SOURCE}" ]]; then
  echo "bootstrap-m3-emit-tu-execute: missing ${SOURCE}" >&2
  exit 1
fi

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-m3-emit-tu-execute: LLVM 9 not found (skip)" >&2
  exit 2
fi

mkdir -p "${ROOT}/build"
export PHP_COMPILER_JIT_PROGRESS_FILE="${ROOT}/build/.last-jit-func-m3-emit-tu-phpunit"
rm -f "${EMIT_HELPER}" "${AOT_OUT}" "${PHP_COMPILER_JIT_PROGRESS_FILE}"

set +e
m3_link_code=1
for _try in 1 2 3 4 5 6 7 8; do
  rm -f "${EMIT_HELPER}"
  m3_link_env=(php)
  if [[ "${BOOTSTRAP_M3_EMIT_SPINE_REAL:-0}" == "1" ]]; then
    m3_link_env=(env PHP_COMPILER_M3_EMIT_SPINE_REAL=1 php)
  fi
  "${m3_link_env[@]}" "${ROOT}/bin/compile.php" -o "${EMIT_HELPER}" "${EMIT_ENTRY}" >/dev/null 2>&1
  m3_link_code=$?
  if [[ "${m3_link_code}" -eq 0 && -x "${EMIT_HELPER}" ]]; then
    break
  fi
  sleep 1
done
set -e

if [[ ! -x "${EMIT_HELPER}" ]]; then
  echo "bootstrap-m3-emit-tu-execute: emit helper link failed ($(m3_exit_label "${m3_link_code}"))" >&2
  exit 1
fi

rm -f "${AOT_OUT}"
set +e
compile_out="$(
  env PHP_COMPILER_M3_EMIT_MINIMAL=1 \
    PHP_COMPILER_M3_SOURCE="${SOURCE}" \
    PHP_COMPILER_M3_OUT="${AOT_OUT}" \
    "${EMIT_HELPER}" 2>&1
)"
native_compile_code=$?
set -e

if [[ "${native_compile_code}" -ne 0 ]] || ! grep -q 'runtime_compile_smoke_m3_emit: compile OK' <<< "${compile_out}"; then
  echo "bootstrap-m3-emit-tu-execute: native emit failed ($(m3_exit_label "${native_compile_code}"))" >&2
  printf '%s\n' "${compile_out}" >&2
  exit 1
fi

if [[ ! -x "${AOT_OUT}" ]]; then
  echo "bootstrap-m3-emit-tu-execute: missing ${AOT_OUT}" >&2
  exit 1
fi

set +e
run_out="$("${AOT_OUT}" 2>&1)"
run_code=$?
set -e

if [[ "${run_code}" -ne 0 ]] || ! grep -q '^1$' <<< "${run_out}"; then
  echo "bootstrap-m3-emit-tu-execute: AOT run failed ($(m3_exit_label "${run_code}"))" >&2
  printf '%s\n' "${run_out}" >&2
  exit 1
fi

echo "bootstrap-m3-emit-tu-execute: OK ${EMIT_HELPER} -> ${AOT_OUT}"
printf 'm3-emit-tu-aot stdout: %s\n' "${run_out}"
