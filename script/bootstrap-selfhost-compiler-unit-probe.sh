#!/usr/bin/env bash
# M3 compiler unit probe: lib/Compiler.php bundle native link + optional native emit (issue #2216, #2618).
set -euo pipefail
if [[ "${BOOTSTRAP_M3_COMPILER_UNIT_PROBE_STRICT:-0}" == "1" ]]; then
  export BOOTSTRAP_M3_LINK_COMPILE_DRIVER="${BOOTSTRAP_M3_LINK_COMPILE_DRIVER:-1}"
  export BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING="${BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING:-1}"
  export BOOTSTRAP_M3_RUNTIME_COMPILE="${BOOTSTRAP_M3_RUNTIME_COMPILE:-1}"
fi
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENTRY="${ROOT}/test/selfhost/compiler_unit_probe/main.php"
OUT="${ROOT}/build/selfhost-compiler-unit-probe"
EMIT_HELPER="${ROOT}/build/selfhost-compiler-unit-probe-emit"
EMIT_ENTRY="${ROOT}/test/bootstrap-aot/compiler_unit_probe_m3_emit_native_entry.php"
SOURCE="${ROOT}/test/bootstrap-aot/compiler_unit_probe_standalone.php"
AOT_OUT="${ROOT}/build/compiler-unit-probe-aot"
M3_NATIVE_COMPILE=0
M3_EMIT_PATH="none"
M3_BLOCK_REASON="native emit helper not linked (set BOOTSTRAP_M3_LINK_COMPILE_DRIVER=1)"

# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
ci_apply_llvm_memory_env

if [[ "${BOOTSTRAP_M3_COMPILER_UNIT_PROBE_STRICT:-0}" == "1" ]]; then
  export BOOTSTRAP_M3_LINK_COMPILE_DRIVER=1
  export BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING=1
  export BOOTSTRAP_M3_RUNTIME_COMPILE=1
fi

m3_exit_label() {
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
  echo "bootstrap-selfhost-compiler-unit-probe: missing ${ENTRY}" >&2
  exit 1
fi

if [[ ! -f "${SOURCE}" ]]; then
  echo "bootstrap-selfhost-compiler-unit-probe: missing ${SOURCE}" >&2
  exit 1
fi

if [[ ! -f "${EMIT_ENTRY}" ]]; then
  echo "bootstrap-selfhost-compiler-unit-probe: missing ${EMIT_ENTRY} (#2618)" >&2
  exit 1
fi

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-selfhost-compiler-unit-probe: LLVM 9 not found (skip)" >&2
  exit 2
fi

mkdir -p "${ROOT}/build"
export PHP_COMPILER_SELFHOST_AOT=1
export PHP_COMPILER_JIT_PROGRESS_FILE="${ROOT}/build/.last-jit-func-compiler-unit-probe"
rm -f "${OUT}" "${EMIT_HELPER}" "${AOT_OUT}" "${PHP_COMPILER_JIT_PROGRESS_FILE}"

if [[ "${BOOTSTRAP_M3_LINK_COMPILE_DRIVER:-0}" == "1" ]]; then
  : "${BOOTSTRAP_M3_RUNTIME_COMPILE:=1}"
  m3_link_env=()
  m3_link_mode="stub"
  if [[ "${BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING:-1}" == "1" ]]; then
    m3_link_env=(env PHP_COMPILER_SELFHOST_AOT=1 PHP_COMPILER_M3_COMPILE_DRIVER=1 PHP_COMPILER_EMIT_HELPER_LINK=1)
    m3_link_mode="selfhost M3 emit TU (compiler_unit_probe_m3_emit_native_entry.php)"
  else
    m3_link_env=(env PHP_COMPILER_SELFHOST_AOT=1)
    m3_link_mode="selfhost stubs (no PHP_COMPILER_M3_COMPILE_DRIVER)"
  fi
  set +e
  "${m3_link_env[@]}" php "${ROOT}/bin/compile.php" -o "${EMIT_HELPER}" "${EMIT_ENTRY}" >/dev/null 2>&1
  m3_link_code=$?
  set -e
  if [[ -x "${EMIT_HELPER}" ]]; then
    echo "bootstrap-selfhost-compiler-unit-probe: native emit helper link OK (${EMIT_HELPER}, ${m3_link_mode})"
    if [[ "${BOOTSTRAP_M3_RUNTIME_COMPILE:-1}" == "1" ]]; then
      set +e
      compile_out="$(
        env PHP_COMPILER_M3_EMIT_MINIMAL=1 \
          PHP_COMPILER_M3_SOURCE="${SOURCE}" \
          PHP_COMPILER_M3_OUT="${AOT_OUT}" \
          "${EMIT_HELPER}" 2>&1
      )"
      native_compile_code=$?
      set -e
      if [[ "${native_compile_code}" -eq 0 ]] && grep -qE 'compile_smoke_m3_emit: compile OK|compiler_unit_probe_m3_emit: compile OK' <<< "${compile_out}"; then
        M3_NATIVE_COMPILE=1
        M3_EMIT_PATH="native"
        M3_BLOCK_REASON=""
        echo "bootstrap-selfhost-compiler-unit-probe: native emit via selfhost emit helper OK"
      else
        if grep -q 'native emit failed at phase=' <<< "${compile_out}"; then
          M3_BLOCK_REASON="$(grep -m1 'native emit failed at phase=' <<< "${compile_out}" | sed 's/^compile_smoke_m3_emit: //')"
        else
          M3_BLOCK_REASON="native emit runtime failed ($(m3_exit_label "${native_compile_code}"))"
        fi
        echo "bootstrap-selfhost-compiler-unit-probe: native emit blocked — ${M3_BLOCK_REASON}" >&2
        printf '%s\n' "${compile_out}" >&2
      fi
    else
      M3_BLOCK_REASON="runtime compile skipped (BOOTSTRAP_M3_RUNTIME_COMPILE=0)"
    fi
  else
    M3_BLOCK_REASON="emit helper link failed ($(m3_exit_label "${m3_link_code}"), mode=${m3_link_mode})"
    echo "bootstrap-selfhost-compiler-unit-probe: ${M3_BLOCK_REASON}" >&2
  fi
fi

if ! php "${ROOT}/bin/compile.php" -o "${OUT}" "${ENTRY}" 2>&1; then
  echo "bootstrap-selfhost-compiler-unit-probe: compile failed (progress gate; see stderr above)" >&2
  exit 1
fi
test -x "${OUT}"

bundle_out="$("${OUT}")"
if ! grep -q 'compiler_unit_probe bundle OK' <<< "${bundle_out}"; then
  echo "bootstrap-selfhost-compiler-unit-probe: unexpected link stdout (want compiler_unit_probe bundle OK)" >&2
  printf '%s\n' "${bundle_out}" >&2
  exit 1
fi

if [[ "${BOOTSTRAP_M3_COMPILER_UNIT_PROBE_STRICT:-0}" == "1" || "${BOOTSTRAP_M3_LINK_COMPILE_DRIVER:-0}" == "1" ]]; then
  if [[ "${M3_NATIVE_COMPILE}" -eq 0 ]]; then
    echo "bootstrap-selfhost-compiler-unit-probe: native emit unavailable — ${M3_BLOCK_REASON}" >&2
    if [[ "${BOOTSTRAP_M3_COMPILER_UNIT_PROBE_STRICT:-0}" == "1" ]]; then
      echo "bootstrap-selfhost-compiler-unit-probe: BOOTSTRAP_M3_COMPILER_UNIT_PROBE_STRICT=1 — require native emit; refusing Zend compile.php fallback" >&2
      echo "bootstrap-selfhost-compiler-unit-probe: emit_path=zend_fallback_would_be_used block_reason=${M3_BLOCK_REASON}" >&2
      exit 1
    fi
  elif [[ ! -x "${AOT_OUT}" ]]; then
    echo "bootstrap-selfhost-compiler-unit-probe: missing executable ${AOT_OUT} (emit_path=${M3_EMIT_PATH})" >&2
    exit 1
  else
    run_out="$("${AOT_OUT}" 2>&1)"
    if ! grep -q 'compiler unit probe' <<< "${run_out}"; then
      echo "bootstrap-selfhost-compiler-unit-probe: unexpected AOT stdout (want compiler unit probe, emit_path=${M3_EMIT_PATH})" >&2
      printf '%s\n' "${run_out}" >&2
      exit 1
    fi
    echo "bootstrap-selfhost-compiler-unit-probe: OK emit_path=native ${EMIT_HELPER} -> ${AOT_OUT}"
    printf 'compiler-unit-probe-aot stdout: %s\n' "${run_out}"
    exit 0
  fi
fi

echo "bootstrap-selfhost-compiler-unit-probe: OK ${OUT}"
