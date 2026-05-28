#!/usr/bin/env bash
# M3 Runtime parseAndCompile self-host probe (issues #1492, #2294).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "${ROOT}"
BUNDLE_ENTRY="${ROOT}/test/selfhost/runtime_compile_smoke/main.php"
SOURCE="${ROOT}/test/bootstrap-aot/runtime_trivial_echo.php"
PROBE="${ROOT}/build/selfhost-runtime-compile-smoke"
EMIT_HELPER="${ROOT}/build/selfhost-runtime-compile-emit"
AOT_OUT="${ROOT}/build/runtime-trivial-aot"
M3_NATIVE_COMPILE=0
M3_EMIT_PATH="none"
M3_BLOCK_REASON="native emit helper not linked (set BOOTSTRAP_M3_LINK_COMPILE_DRIVER=1)"
# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
ci_apply_llvm_memory_env

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

if [[ ! -f "${BUNDLE_ENTRY}" ]]; then
  echo "bootstrap-selfhost-runtime-compile-smoke: missing ${BUNDLE_ENTRY}" >&2
  exit 1
fi

if [[ ! -f "${SOURCE}" ]]; then
  echo "bootstrap-selfhost-runtime-compile-smoke: missing ${SOURCE}" >&2
  exit 1
fi

EMIT_ENTRY="${ROOT}/test/bootstrap-aot/runtime_m3_emit_native_entry.php"
INVENTORY_EMIT_DRIVER="${ROOT}/test/selfhost/runtime_compile_smoke/compile_driver.php"
USE_INVENTORY_EMIT_DRIVER="${BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER:-0}"
if [[ ! -f "${EMIT_ENTRY}" ]]; then
  echo "bootstrap-selfhost-runtime-compile-smoke: missing ${EMIT_ENTRY}" >&2
  exit 1
fi

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-selfhost-runtime-compile-smoke: LLVM 9 not found (skip)" >&2
  exit 2
fi

# Default-on native compile-driver when LLVM present (mirror bootstrap-loop-gen1-link; #2620).
: "${BOOTSTRAP_M3_LINK_COMPILE_DRIVER:=1}"
: "${BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING:=1}"
: "${BOOTSTRAP_M3_RUNTIME_COMPILE:=1}"

mkdir -p "${ROOT}/build"
export PHP_COMPILER_SELFHOST_AOT=1
unset PHP_COMPILER_M3_COMPILE_DRIVER
rm -f "${PROBE}" "${EMIT_HELPER}" "${AOT_OUT}"

if [[ "${BOOTSTRAP_M3_LINK_COMPILE_DRIVER:-0}" == "1" ]]; then
  : "${BOOTSTRAP_M3_RUNTIME_COMPILE:=1}"
  m3_link_env=()
  m3_link_mode="stub"
  m3_emit_source="${EMIT_ENTRY}"
  # Default REAL_LOWERING on when LINK_COMPILE_DRIVER=1 (stub-only path fails link — #2571, #2582).
  if [[ "${BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING:-1}" == "1" ]]; then
    if [[ "${USE_INVENTORY_EMIT_DRIVER}" == "1" && -f "${INVENTORY_EMIT_DRIVER}" ]]; then
      m3_link_env=(env PHP_COMPILER_SELFHOST_AOT=1 PHP_COMPILER_M3_COMPILE_DRIVER=1 PHP_COMPILER_EMIT_HELPER_LINK=1 PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1 BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER=1 PHP_COMPILER_M3_EMIT_LOG_PREFIX=runtime_compile_smoke_m3_emit PHP_COMPILER_M3_EMIT_HELPER_SPINE=1)
      m3_link_mode="inventory compile_driver (no emit-helper TU, #2879)"
      m3_emit_source="${INVENTORY_EMIT_DRIVER}"
    else
      m3_link_env=(env PHP_COMPILER_SELFHOST_AOT=1 PHP_COMPILER_M3_COMPILE_DRIVER=1 PHP_COMPILER_EMIT_HELPER_LINK=1)
      m3_link_mode="selfhost M3 emit TU (runtime_m3_emit_native_entry.php)"
    fi
  else
    m3_link_env=(env PHP_COMPILER_SELFHOST_AOT=1 PHP_COMPILER_EMIT_HELPER_LINK=1)
    m3_link_mode="selfhost stubs (no PHP_COMPILER_M3_COMPILE_DRIVER)"
  fi
  set +e
  for _try in 1 2 3 4 5 6 7 8; do
    rm -f "${EMIT_HELPER}"
    "${m3_link_env[@]}" php bin/compile.php -o build/selfhost-runtime-compile-emit "${m3_emit_source}" >/dev/null 2>&1
    m3_link_code=$?
    if [[ "${m3_link_code}" -eq 0 && -x "${EMIT_HELPER}" ]]; then
      break
    fi
    sleep 1
  done
  set -e
  if [[ -x "${EMIT_HELPER}" ]]; then
    echo "bootstrap-selfhost-runtime-compile-smoke: native emit helper link OK (${EMIT_HELPER}, ${m3_link_mode})"
    if [[ "${BOOTSTRAP_M3_RUNTIME_COMPILE:-1}" == "1" ]]; then
      set +e
      m3_run_env=(PHP_COMPILER_M3_EMIT_MINIMAL=1 PHP_COMPILER_M3_SOURCE="${SOURCE}" PHP_COMPILER_M3_OUT="${AOT_OUT}")
      if [[ "${USE_INVENTORY_EMIT_DRIVER}" == "1" ]]; then
        m3_run_env+=(PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1)
      fi
      compile_out="$(
        env "${m3_run_env[@]}" "${EMIT_HELPER}" 2>&1
      )"
      native_compile_code=$?
      set -e
      if [[ "${native_compile_code}" -eq 0 ]] && grep -q 'runtime_compile_smoke_m3_emit: compile OK' <<< "${compile_out}"; then
        if [[ -f "${AOT_OUT}" ]]; then
          chmod +x "${AOT_OUT}" 2>/dev/null || true
        fi
        M3_NATIVE_COMPILE=1
        M3_EMIT_PATH="native"
        M3_BLOCK_REASON=""
        echo "bootstrap-selfhost-runtime-compile-smoke: native emit via Runtime parseAndCompile OK"
      else
        if grep -q 'native emit failed at phase=' <<< "${compile_out}"; then
          M3_BLOCK_REASON="$(grep -m1 'native emit failed at phase=' <<< "${compile_out}" | sed 's/^runtime_compile_smoke_m3_emit: //')"
        else
          M3_BLOCK_REASON="native emit runtime failed ($(m3_exit_label "${native_compile_code}"))"
        fi
        echo "bootstrap-selfhost-runtime-compile-smoke: native emit blocked — ${M3_BLOCK_REASON}" >&2
        printf '%s\n' "${compile_out}" >&2
      fi
    else
      M3_BLOCK_REASON="runtime compile skipped (BOOTSTRAP_M3_RUNTIME_COMPILE=0)"
    fi
  else
    M3_BLOCK_REASON="emit helper link failed ($(m3_exit_label "${m3_link_code}"), mode=${m3_link_mode})"
    echo "bootstrap-selfhost-runtime-compile-smoke: ${M3_BLOCK_REASON}" >&2
  fi
fi

export PHP_COMPILER_JIT_PROGRESS_FILE="${ROOT}/build/.last-jit-func-runtime-compile-smoke"
rm -f "${PHP_COMPILER_JIT_PROGRESS_FILE}"

if ! php bin/compile.php -o build/selfhost-runtime-compile-smoke test/selfhost/runtime_compile_smoke/main.php 2>&1; then
  echo "bootstrap-selfhost-runtime-compile-smoke: link bundle failed (see stderr above)" >&2
  exit 1
fi
test -x "${PROBE}"

bundle_out="$("${PROBE}")"
if ! grep -q 'runtime_compile_smoke bundle OK' <<< "${bundle_out}"; then
  echo "bootstrap-selfhost-runtime-compile-smoke: unexpected bundle stdout (want runtime_compile_smoke bundle OK)" >&2
  printf '%s\n' "${bundle_out}" >&2
  exit 1
fi

if [[ "${M3_NATIVE_COMPILE}" -eq 0 ]]; then
  echo "bootstrap-selfhost-runtime-compile-smoke: native emit unavailable (M3 partial) — ${M3_BLOCK_REASON}" >&2
  if [[ "${BOOTSTRAP_M3_RUNTIME_COMPILE_SMOKE_STRICT:-0}" == "1" ]]; then
    echo "bootstrap-selfhost-runtime-compile-smoke: BOOTSTRAP_M3_RUNTIME_COMPILE_SMOKE_STRICT=1 — require native emit; refusing Zend compile.php fallback" >&2
    echo "bootstrap-selfhost-runtime-compile-smoke: emit_path=zend_fallback_would_be_used block_reason=${M3_BLOCK_REASON}" >&2
    exit 1
  fi
  M3_EMIT_PATH="zend"
  echo "bootstrap-selfhost-runtime-compile-smoke: emit_path=zend (bin/compile.php) — Runtime trivial AOT until native spine is ready" >&2
  rm -f "${AOT_OUT}"
  if ! php bin/compile.php -o build/runtime-trivial-aot test/bootstrap-aot/runtime_trivial_echo.php 2>&1; then
    echo "bootstrap-selfhost-runtime-compile-smoke: Zend runtime trivial emit failed (emit_path=zend)" >&2
    exit 1
  fi
fi

if [[ ! -x "${AOT_OUT}" ]]; then
  echo "bootstrap-selfhost-runtime-compile-smoke: missing executable ${AOT_OUT} (emit_path=${M3_EMIT_PATH})" >&2
  exit 1
fi

run_out="$("${AOT_OUT}" 2>&1)"
if ! grep -q '^1$' <<< "${run_out}"; then
  echo "bootstrap-selfhost-runtime-compile-smoke: unexpected AOT stdout (want 1, emit_path=${M3_EMIT_PATH})" >&2
  printf '%s\n' "${run_out}" >&2
  exit 1
fi

if [[ "${M3_NATIVE_COMPILE}" -eq 1 ]]; then
  echo "bootstrap-selfhost-runtime-compile-smoke: OK emit_path=native ${EMIT_HELPER} -> ${AOT_OUT}"
else
  echo "bootstrap-selfhost-runtime-compile-smoke: OK emit_path=zend partial ${PROBE} -> ${AOT_OUT} (native run)"
fi
printf 'runtime-trivial-aot stdout: %s\n' "${run_out}"
