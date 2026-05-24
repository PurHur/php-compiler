#!/usr/bin/env bash
# M4 gen-1 link + gen-2 compile attempt (issue #1498): bootstrap_loop_smoke bundle → gen-2 smoke binary.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENTRY="${ROOT}/test/selfhost/bootstrap_loop_smoke/main.php"
GEN1="${ROOT}/build/bootstrap-loop-gen1"
COMPILE_DRIVER="${ROOT}/build/bootstrap-loop-gen1-compile"
GEN2_SOURCE="${ROOT}/test/bootstrap-aot/compiler_smoke_standalone.php"
GEN2_OUT="${ROOT}/build/bootstrap-loop-gen2"
M4_NATIVE_COMPILE=0
M4_EMIT_PATH="none"
M4_BLOCK_REASON="native compile driver not linked (set BOOTSTRAP_M4_LINK_COMPILE_DRIVER=1)"
# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
ci_apply_llvm_memory_env

m4_gen_exit_label() {
  local code=$1
  if [[ "${code}" -eq 139 ]]; then
    echo "segfault (LLVM 9 link/lowering; see docs/bootstrap-m5-fast-path.md deny list)"
  elif [[ "${code}" -eq 137 ]]; then
    echo "SIGKILL (likely OOM during link)"
  elif [[ "${code}" -ne 0 ]]; then
    echo "exit ${code}"
  else
    echo "ok"
  fi
}

if [[ ! -f "${ENTRY}" ]]; then
  echo "bootstrap-loop-gen1-link: missing ${ENTRY}" >&2
  exit 1
fi

if [[ ! -f "${GEN2_SOURCE}" ]]; then
  echo "bootstrap-loop-gen1-link: missing ${GEN2_SOURCE}" >&2
  exit 1
fi

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-loop-gen1-link: LLVM 9 not found (skip)" >&2
  exit 2
fi

mkdir -p "${ROOT}/build"
export PHP_COMPILER_SELFHOST_AOT=1
export PHP_COMPILER_JIT_PROGRESS_FILE="${ROOT}/build/.last-jit-func-bootstrap-loop-gen1"
rm -f "${GEN1}" "${COMPILE_DRIVER}" "${GEN2_OUT}" "${PHP_COMPILER_JIT_PROGRESS_FILE}"

echo "==> link gen-1 (bootstrap_loop_smoke bundle)"
if ! php "${ROOT}/bin/compile.php" -o "${GEN1}" "${ENTRY}" 2>&1; then
  echo "bootstrap-loop-gen1-link: gen-1 link failed (see stderr above)" >&2
  exit 1
fi
test -x "${GEN1}"

gen1_out="$("${GEN1}")"
if ! grep -q 'bootstrap_loop_smoke bundle OK' <<< "${gen1_out}"; then
  echo "bootstrap-loop-gen1-link: unexpected gen-1 stdout (want bootstrap_loop_smoke bundle OK)" >&2
  printf '%s\n' "${gen1_out}" >&2
  exit 1
fi
echo "bootstrap-loop-gen1-link: gen-1 link OK (${GEN1})"

if [[ "${BOOTSTRAP_M4_LINK_COMPILE_DRIVER:-0}" == "1" ]]; then
  echo "==> link gen-1 compile driver (opt-in)"
  set +e
  env PHP_COMPILER_SELFHOST_AOT=1 php "${ROOT}/bin/compile.php" -o "${COMPILE_DRIVER}" \
    "${ROOT}/test/selfhost/bootstrap_loop_smoke/compile_driver.php" 2>&1
  driver_link_code=$?
  set -e
  if [[ "${driver_link_code}" -eq 0 ]]; then
    echo "bootstrap-loop-gen1-link: compile driver link OK (${COMPILE_DRIVER})"
    set +e
    runtime_env=(
      env PHP_COMPILER_M4_COMPILE_MODE=compile
      PHP_COMPILER_M4_SOURCE="${GEN2_SOURCE}"
      PHP_COMPILER_M4_OUT="${GEN2_OUT}"
    )
    if [[ "${BOOTSTRAP_M4_RUNTIME_COMPILE:-0}" == "1" ]]; then
      runtime_env+=(PHP_COMPILER_M4_RUNTIME_COMPILE=1)
    fi
    compile_out="$({ "${runtime_env[@]}" env -u PHP_COMPILER_SELFHOST_AOT "${COMPILE_DRIVER}"; } 2>&1)"
    native_compile_code=$?
    set -e
    if [[ "${native_compile_code}" -eq 0 ]] && grep -qE 'helloworld_compile_smoke: compile OK|bootstrap_loop_compile_smoke: gen-2 compile OK' <<< "${compile_out}"; then
      M4_NATIVE_COMPILE=1
      M4_EMIT_PATH="native"
      M4_BLOCK_REASON=""
      echo "bootstrap-loop-gen1-link: gen-1 native emit OK (${COMPILE_DRIVER} -> ${GEN2_OUT})"
    else
      if [[ "${BOOTSTRAP_M4_RUNTIME_COMPILE:-0}" != "1" ]]; then
        M4_BLOCK_REASON="runtime gate: BOOTSTRAP_M4_RUNTIME_COMPILE=1 not set (M3 spine still stubbed)"
      elif grep -q 'native emit failed at phase=' <<< "${compile_out}"; then
        M4_BLOCK_REASON="$(grep -m1 'native emit failed at phase=' <<< "${compile_out}" | sed 's/^[^:]*: //')"
      elif grep -q 'emit path blocked' <<< "${compile_out}"; then
        M4_BLOCK_REASON="$(grep -m1 'emit path blocked' <<< "${compile_out}" | sed 's/^[^:]*: //')"
      else
        M4_BLOCK_REASON="gen-1 native compile runtime failed ($(m4_gen_exit_label "${native_compile_code}"))"
      fi
      echo "bootstrap-loop-gen1-link: gen-1 native emit blocked — ${M4_BLOCK_REASON}" >&2
      printf '%s\n' "${compile_out}" >&2
    fi
  else
    M4_BLOCK_REASON="compile driver link failed ($(m4_gen_exit_label "${driver_link_code}"))"
    echo "bootstrap-loop-gen1-link: ${M4_BLOCK_REASON}" >&2
  fi
fi

if [[ "${M4_NATIVE_COMPILE}" -eq 0 ]]; then
  if [[ "${BOOTSTRAP_M4_GEN2_STRICT:-0}" == "1" ]]; then
    echo "bootstrap-loop-gen1-link: BOOTSTRAP_M4_GEN2_STRICT=1 — require native gen-2 emit; refusing Zend fallback" >&2
    echo "bootstrap-loop-gen1-link: emit_path=zend_fallback_would_be_used block_reason=${M4_BLOCK_REASON}" >&2
    exit 1
  fi
  M4_EMIT_PATH="zend"
  echo "bootstrap-loop-gen1-link: gen-2 emit_path=zend (bin/compile.php) — ${M4_BLOCK_REASON}" >&2
  rm -f "${GEN2_OUT}"
  if ! php "${ROOT}/bin/compile.php" -o "${GEN2_OUT}" "${GEN2_SOURCE}" 2>&1; then
    echo "bootstrap-loop-gen1-link: Zend gen-2 emit failed" >&2
    exit 1
  fi
fi

if [[ ! -x "${GEN2_OUT}" ]]; then
  echo "bootstrap-loop-gen1-link: missing gen-2 executable ${GEN2_OUT} (emit_path=${M4_EMIT_PATH})" >&2
  exit 1
fi

gen2_out="$("${GEN2_OUT}" 2>&1)"
if ! grep -q 'compiler smoke' <<< "${gen2_out}"; then
  echo "bootstrap-loop-gen1-link: unexpected gen-2 stdout (want compiler smoke, emit_path=${M4_EMIT_PATH})" >&2
  printf '%s\n' "${gen2_out}" >&2
  exit 1
fi

if [[ "${M4_NATIVE_COMPILE}" -eq 1 ]]; then
  echo "bootstrap-loop-gen1-link: OK emit_path=native gen-1=${GEN1} gen-2=${GEN2_OUT}"
else
  echo "bootstrap-loop-gen1-link: OK emit_path=zend partial gen-1=${GEN1} gen-2=${GEN2_OUT} (gen-1 native compile blocked)"
fi
printf 'gen-2 stdout: %s\n' "${gen2_out}"
