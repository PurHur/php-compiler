#!/usr/bin/env bash
# M3 compile-smoke self-host probe (issues #1056, #1492, #1937, #1983): link bundle, native or Zend emit, run natively.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "${ROOT}"
BUNDLE_ENTRY="${ROOT}/test/selfhost/compiler_compile_smoke/main.php"
SOURCE="${ROOT}/test/bootstrap-aot/compiler_smoke_standalone.php"
PROBE="${ROOT}/build/selfhost-compile-smoke-probe"
EMIT_HELPER="${ROOT}/build/selfhost-compile-smoke-emit"
AOT_OUT="${ROOT}/build/compile-smoke-aot"
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
  echo "bootstrap-selfhost-compile-smoke-probe: missing ${BUNDLE_ENTRY}" >&2
  exit 1
fi

if [[ ! -f "${SOURCE}" ]]; then
  echo "bootstrap-selfhost-compile-smoke-probe: missing ${SOURCE}" >&2
  exit 1
fi

EMIT_ENTRY="${ROOT}/test/bootstrap-aot/compile_smoke_m3_emit_native_entry.php"
if [[ ! -f "${EMIT_ENTRY}" ]]; then
  echo "bootstrap-selfhost-compile-smoke-probe: missing ${EMIT_ENTRY} (#1983)" >&2
  exit 1
fi

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-selfhost-compile-smoke-probe: LLVM 9 not found (skip)" >&2
  exit 2
fi

# Default-on native compile-driver when LLVM present (mirror bootstrap-loop-gen1-link; #2620).
: "${BOOTSTRAP_M3_LINK_COMPILE_DRIVER:=1}"
: "${BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING:=1}"
: "${BOOTSTRAP_M3_RUNTIME_COMPILE:=1}"

mkdir -p "${ROOT}/build"
export PHP_COMPILER_SELFHOST_AOT=1
rm -f "${PROBE}" "${EMIT_HELPER}" "${AOT_OUT}"

if [[ "${BOOTSTRAP_M3_LINK_COMPILE_DRIVER:-0}" == "1" ]]; then
  : "${BOOTSTRAP_M3_RUNTIME_COMPILE:=1}"
  m3_link_env=()
  m3_link_mode="stub"
  # Default REAL_LOWERING on when LINK_COMPILE_DRIVER=1 (stub-only path fails link — #2571, #2582).
  if [[ "${BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING:-1}" == "1" ]]; then
    # Self-host M3 allowlist — full Runtime JIT without stubs segfaults at link (#1937, #1983).
    m3_link_env=(env PHP_COMPILER_SELFHOST_AOT=1 PHP_COMPILER_M3_COMPILE_DRIVER=1 PHP_COMPILER_EMIT_HELPER_LINK=1)
    m3_link_mode="selfhost M3 emit TU (compile_smoke_m3_emit_native_entry.php)"
  else
    m3_link_env=(env PHP_COMPILER_SELFHOST_AOT=1)
    m3_link_mode="selfhost stubs (no PHP_COMPILER_M3_COMPILE_DRIVER)"
  fi
  set +e
  "${m3_link_env[@]}" php bin/compile.php -o build/selfhost-compile-smoke-emit "${EMIT_ENTRY}" >/dev/null 2>&1
  m3_link_code=$?
  set -e
  if [[ -x "${EMIT_HELPER}" ]]; then
    echo "bootstrap-selfhost-compile-smoke-probe: native emit helper link OK (${EMIT_HELPER}, ${m3_link_mode})"
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
      if [[ "${native_compile_code}" -eq 0 ]] && grep -q 'compile_smoke_m3_emit: compile OK' <<< "${compile_out}"; then
        M3_NATIVE_COMPILE=1
        M3_EMIT_PATH="native"
        M3_BLOCK_REASON=""
        echo "bootstrap-selfhost-compile-smoke-probe: native emit via selfhost emit helper OK"
      else
        if grep -q 'native emit failed at phase=' <<< "${compile_out}"; then
          M3_BLOCK_REASON="$(grep -m1 'native emit failed at phase=' <<< "${compile_out}" | sed 's/^compile_smoke_m3_emit: //')"
        else
          M3_BLOCK_REASON="native emit runtime failed ($(m3_exit_label "${native_compile_code}"))"
        fi
        echo "bootstrap-selfhost-compile-smoke-probe: native emit blocked — ${M3_BLOCK_REASON}" >&2
        printf '%s\n' "${compile_out}" >&2
      fi
    else
      M3_BLOCK_REASON="runtime compile skipped (BOOTSTRAP_M3_RUNTIME_COMPILE=0)"
    fi
  else
    M3_BLOCK_REASON="emit helper link failed ($(m3_exit_label "${m3_link_code}"), mode=${m3_link_mode})"
    echo "bootstrap-selfhost-compile-smoke-probe: ${M3_BLOCK_REASON}" >&2
  fi
fi

export PHP_COMPILER_JIT_PROGRESS_FILE="${ROOT}/build/.last-jit-func-compile-smoke-probe"
rm -f "${PHP_COMPILER_JIT_PROGRESS_FILE}"

if ! php bin/compile.php -o build/selfhost-compile-smoke-probe test/selfhost/compiler_compile_smoke/main.php 2>&1; then
  echo "bootstrap-selfhost-compile-smoke-probe: link bundle failed (see stderr above)" >&2
  exit 1
fi
test -x "${PROBE}"

bundle_out="$("${PROBE}")"
if ! grep -q 'compiler_compile_smoke bundle OK' <<< "${bundle_out}"; then
  echo "bootstrap-selfhost-compile-smoke-probe: unexpected bundle stdout (want compiler_compile_smoke bundle OK)" >&2
  printf '%s\n' "${bundle_out}" >&2
  exit 1
fi

if [[ "${M3_NATIVE_COMPILE}" -eq 0 ]]; then
  echo "bootstrap-selfhost-compile-smoke-probe: native emit unavailable (M3 partial) — ${M3_BLOCK_REASON}" >&2
  if [[ "${BOOTSTRAP_M3_COMPILE_SMOKE_STRICT:-0}" == "1" ]]; then
    echo "bootstrap-selfhost-compile-smoke-probe: BOOTSTRAP_M3_COMPILE_SMOKE_STRICT=1 — require native emit; refusing Zend compile.php fallback" >&2
    echo "bootstrap-selfhost-compile-smoke-probe: emit_path=zend_fallback_would_be_used block_reason=${M3_BLOCK_REASON}" >&2
    exit 1
  fi
  M3_EMIT_PATH="zend"
  echo "bootstrap-selfhost-compile-smoke-probe: emit_path=zend (bin/compile.php) — compile-smoke AOT until native spine is ready" >&2
  rm -f "${AOT_OUT}"
  if ! php bin/compile.php -o build/compile-smoke-aot test/bootstrap-aot/compiler_smoke_standalone.php 2>&1; then
    echo "bootstrap-selfhost-compile-smoke-probe: Zend compile-smoke emit failed (emit_path=zend)" >&2
    exit 1
  fi
fi

if [[ ! -x "${AOT_OUT}" ]]; then
  echo "bootstrap-selfhost-compile-smoke-probe: missing executable ${AOT_OUT} (emit_path=${M3_EMIT_PATH})" >&2
  exit 1
fi

run_out="$("${AOT_OUT}" 2>&1)"
if ! grep -q 'compiler smoke' <<< "${run_out}"; then
  echo "bootstrap-selfhost-compile-smoke-probe: unexpected AOT stdout (want compiler smoke, emit_path=${M3_EMIT_PATH})" >&2
  printf '%s\n' "${run_out}" >&2
  exit 1
fi

if [[ "${M3_NATIVE_COMPILE}" -eq 1 ]]; then
  echo "bootstrap-selfhost-compile-smoke-probe: OK emit_path=native ${EMIT_HELPER} -> ${AOT_OUT}"
else
  echo "bootstrap-selfhost-compile-smoke-probe: OK emit_path=zend partial ${PROBE} -> ${AOT_OUT} (native run)"
fi
printf 'compile-smoke-aot stdout: %s\n' "${run_out}"
