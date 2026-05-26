#!/usr/bin/env bash
# M4 gen-1 link + gen-2 compile attempt (issue #1498): bootstrap_loop_smoke bundle → gen-2 smoke binary.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "${ROOT}"
ENTRY="test/selfhost/bootstrap_loop_smoke/main.php"
GEN1="build/bootstrap-loop-gen1"
EMIT_HELPER="build/bootstrap-loop-gen1-compile"
EMIT_ENTRY="test/bootstrap-aot/compile_smoke_m3_emit_native_entry.php"
EMIT_ENTRY_ABS="${ROOT}/test/bootstrap-aot/compile_smoke_m3_emit_native_entry.php"
GEN2_SOURCE="test/bootstrap-aot/compiler_smoke_standalone.php"
GEN2_OUT="build/bootstrap-loop-gen2"
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
  echo "bootstrap-loop-gen1-link: missing ${ROOT}/${ENTRY}" >&2
  exit 1
fi

if [[ ! -f "${GEN2_SOURCE}" ]]; then
  echo "bootstrap-loop-gen1-link: missing ${ROOT}/${GEN2_SOURCE}" >&2
  exit 1
fi

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-loop-gen1-link: LLVM 9 not found (skip)" >&2
  exit 2
fi

mkdir -p build
export PHP_COMPILER_SELFHOST_AOT=1
export PHP_COMPILER_JIT_PROGRESS_FILE="build/.last-jit-func-bootstrap-loop-gen1"
rm -f "${GEN1}" "${EMIT_HELPER}" "${GEN2_OUT}" "${PHP_COMPILER_JIT_PROGRESS_FILE}"

if [[ ! -f "${EMIT_ENTRY}" ]]; then
  echo "bootstrap-loop-gen1-link: missing ${ROOT}/${EMIT_ENTRY} (#1983)" >&2
  exit 1
fi

if [[ "${BOOTSTRAP_M4_LINK_COMPILE_DRIVER:-0}" == "1" ]]; then
  m4_link_env=()
  m4_link_mode="stub"
  m4_emit_entry="${EMIT_ENTRY}"
  # Default REAL_LOWERING on when LINK_COMPILE_DRIVER=1 so emit helper links with
  # PHP_COMPILER_M3_COMPILE_DRIVER (stub-only path always fails link — #2571).
  if [[ "${BOOTSTRAP_M4_COMPILE_DRIVER_REAL_LOWERING:-${BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING:-1}}" == "1" ]]; then
    m4_link_env=(env PHP_COMPILER_SELFHOST_AOT=1 PHP_COMPILER_M3_COMPILE_DRIVER=1 PHP_COMPILER_EMIT_HELPER_LINK=1)
    m4_link_mode="selfhost M3 emit TU (compile_smoke_m3_emit_native_entry.php)"
    # bin/compile.php bootstrap-aot gate matches /test/bootstrap-aot/ in normalized paths (#1983).
    m4_emit_entry="${EMIT_ENTRY_ABS}"
  else
    m4_link_env=(env PHP_COMPILER_SELFHOST_AOT=1)
    m4_link_mode="selfhost stubs (no PHP_COMPILER_M3_COMPILE_DRIVER)"
  fi
  echo "==> link gen-1 emit helper (opt-in; compile_driver.php lint-only — LLVM 9 link crash #1768)"
  rm -f "${EMIT_HELPER}" "build/.last-jit-func-bootstrap-loop-gen1-emit"
  export PHP_COMPILER_JIT_PROGRESS_FILE="build/.last-jit-func-bootstrap-loop-gen1-emit"
  set +e
  "${m4_link_env[@]}" php bin/compile.php -o "${EMIT_HELPER}" "${m4_emit_entry}" >/dev/null 2>&1
  emit_link_code=$?
  set -e
  if [[ -x "${EMIT_HELPER}" ]]; then
    echo "bootstrap-loop-gen1-link: emit helper link OK (${ROOT}/${EMIT_HELPER}, ${m4_link_mode})"
    # Default native emit execute when compile-driver link is on (#2599).
    m4_runtime_compile="${BOOTSTRAP_M4_RUNTIME_COMPILE:-${BOOTSTRAP_M4_LINK_COMPILE_DRIVER:-0}}"
    if [[ "${m4_runtime_compile}" == "1" ]]; then
      set +e
      compile_out="$(
        env PHP_COMPILER_M3_EMIT_MINIMAL=1 \
          PHP_COMPILER_M3_SOURCE="${ROOT}/${GEN2_SOURCE}" \
          PHP_COMPILER_M3_OUT="${ROOT}/${GEN2_OUT}" \
          "./${EMIT_HELPER}" 2>&1
      )"
      native_compile_code=$?
      set -e
      if [[ "${native_compile_code}" -eq 0 ]] && grep -qE 'compile_smoke_m3_emit: compile OK|helloworld_compile_smoke: compile OK|bootstrap_loop_compile_smoke: gen-2 compile OK' <<< "${compile_out}"; then
        M4_NATIVE_COMPILE=1
        M4_EMIT_PATH="native"
        M4_BLOCK_REASON=""
        echo "bootstrap-loop-gen1-link: gen-1 native emit OK (${ROOT}/${EMIT_HELPER} -> ${ROOT}/${GEN2_OUT})"
      else
        if grep -q 'native emit failed at phase=' <<< "${compile_out}"; then
          M4_BLOCK_REASON="$(grep -m1 'native emit failed at phase=' <<< "${compile_out}" | sed 's/^[^:]*: //')"
        else
          M4_BLOCK_REASON="gen-1 native emit runtime failed ($(m4_gen_exit_label "${native_compile_code}"))"
        fi
        echo "bootstrap-loop-gen1-link: gen-1 native emit blocked — ${M4_BLOCK_REASON}" >&2
        printf '%s\n' "${compile_out}" >&2
      fi
    else
      M4_BLOCK_REASON="runtime gate: native emit execute disabled (BOOTSTRAP_M4_RUNTIME_COMPILE=0 with link driver — #2599)"
    fi
  else
    M4_BLOCK_REASON="emit helper link failed ($(m4_gen_exit_label "${emit_link_code}"), mode=${m4_link_mode})"
    echo "bootstrap-loop-gen1-link: ${M4_BLOCK_REASON}" >&2
  fi
  export PHP_COMPILER_JIT_PROGRESS_FILE="build/.last-jit-func-bootstrap-loop-gen1"
fi

echo "==> link gen-1 (bootstrap_loop_smoke bundle)"
if ! php bin/compile.php -o "${GEN1}" "${ENTRY}" 2>&1; then
  echo "bootstrap-loop-gen1-link: gen-1 link failed (see stderr above)" >&2
  exit 1
fi
test -x "${GEN1}"

gen1_out="$("./${GEN1}")"
if ! grep -q 'bootstrap_loop_smoke bundle OK' <<< "${gen1_out}"; then
  echo "bootstrap-loop-gen1-link: unexpected gen-1 stdout (want bootstrap_loop_smoke bundle OK)" >&2
  printf '%s\n' "${gen1_out}" >&2
  exit 1
fi
echo "bootstrap-loop-gen1-link: gen-1 link OK (${ROOT}/${GEN1})"

if [[ "${M4_NATIVE_COMPILE}" -eq 0 ]]; then
  if [[ "${BOOTSTRAP_M4_GEN2_STRICT:-0}" == "1" ]]; then
    echo "bootstrap-loop-gen1-link: BOOTSTRAP_M4_GEN2_STRICT=1 — require native gen-2 emit; refusing Zend fallback" >&2
    echo "bootstrap-loop-gen1-link: emit_path=zend_fallback_would_be_used block_reason=${M4_BLOCK_REASON}" >&2
    exit 1
  fi
  M4_EMIT_PATH="zend"
  echo "bootstrap-loop-gen1-link: gen-2 emit_path=zend (bin/compile.php) — ${M4_BLOCK_REASON}" >&2
  rm -f "${GEN2_OUT}"
  if ! php bin/compile.php -o "${GEN2_OUT}" "${GEN2_SOURCE}" 2>&1; then
    echo "bootstrap-loop-gen1-link: Zend gen-2 emit failed" >&2
    exit 1
  fi
fi

if [[ ! -x "${GEN2_OUT}" ]]; then
  echo "bootstrap-loop-gen1-link: missing gen-2 executable ${ROOT}/${GEN2_OUT} (emit_path=${M4_EMIT_PATH})" >&2
  exit 1
fi

gen2_out="$("./${GEN2_OUT}" 2>&1)"
if ! grep -q 'compiler smoke' <<< "${gen2_out}"; then
  echo "bootstrap-loop-gen1-link: unexpected gen-2 stdout (want compiler smoke, emit_path=${M4_EMIT_PATH})" >&2
  printf '%s\n' "${gen2_out}" >&2
  exit 1
fi

if [[ "${M4_NATIVE_COMPILE}" -eq 1 ]]; then
  echo "bootstrap-loop-gen1-link: OK emit_path=native gen-1=${ROOT}/${GEN1} gen-2=${ROOT}/${GEN2_OUT}"
else
  echo "bootstrap-loop-gen1-link: OK emit_path=zend partial gen-1=${ROOT}/${GEN1} gen-2=${ROOT}/${GEN2_OUT} (gen-1 native compile blocked)"
fi
printf 'gen-2 stdout: %s\n' "${gen2_out}"
