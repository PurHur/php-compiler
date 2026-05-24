#!/usr/bin/env bash
# M3 HelloWorld self-host probe (issue #1056): link selfhost bundle, compile HelloWorld AOT, run natively.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENTRY="${ROOT}/test/selfhost/compiler_helloworld_smoke/main.php"
PROBE="${ROOT}/build/selfhost-helloworld"
SOURCE="${ROOT}/examples/000-HelloWorld/example.php"
AOT_OUT="${ROOT}/build/helloworld-aot"
COMPILE_DRIVER="${ROOT}/build/selfhost-helloworld-compile"
MODE_FILE="${ROOT}/build/.m3-helloworld-mode"
M3_NATIVE_COMPILE=0
M3_EMIT_PATH="none"
M3_BLOCK_REASON="native compile driver not linked (set BOOTSTRAP_M3_LINK_COMPILE_DRIVER=1)"
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
rm -f "${PROBE}" "${COMPILE_DRIVER}" "${AOT_OUT}" "${MODE_FILE}" "${PHP_COMPILER_JIT_PROGRESS_FILE}"

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

# Native compile driver link is opt-in; runtime dispatch uses mode file (#1056).
if [[ "${BOOTSTRAP_M3_LINK_COMPILE_DRIVER:-0}" == "1" ]]; then
  m3_link_env=()
  m3_link_mode="stub"
  if [[ "${BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING:-0}" == "1" ]]; then
    m3_link_env=(env PHP_COMPILER_SELFHOST_AOT=1 PHP_COMPILER_M3_COMPILE_DRIVER=1)
    m3_link_mode="real-lowering (helloworld_compile_smoke + Runtime::parseAndCompile allowlist)"
  else
    m3_link_env=(env PHP_COMPILER_SELFHOST_AOT=1)
    m3_link_mode="selfhost stubs (no PHP_COMPILER_M3_COMPILE_DRIVER)"
  fi
  set +e
  "${m3_link_env[@]}" php "${ROOT}/bin/compile.php" -o "${COMPILE_DRIVER}" "${ROOT}/test/selfhost/compiler_helloworld_smoke/compile_driver.php" 2>&1
  m3_link_code=$?
  set -e
  if [[ "${m3_link_code}" -eq 0 ]]; then
    echo "bootstrap-selfhost-helloworld-probe: compile driver link OK (${COMPILE_DRIVER}, ${m3_link_mode})"
    set +e
    runtime_env=(
      env PHP_COMPILER_M3_COMPILE_MODE=compile
      PHP_COMPILER_M3_SOURCE="${SOURCE}"
      PHP_COMPILER_M3_OUT="${AOT_OUT}"
    )
    if [[ "${BOOTSTRAP_M3_RUNTIME_COMPILE:-0}" == "1" ]]; then
      runtime_env+=(PHP_COMPILER_M3_RUNTIME_COMPILE=1)
    fi
    compile_out="$({ "${runtime_env[@]}" env -u PHP_COMPILER_SELFHOST_AOT "${COMPILE_DRIVER}"; } 2>&1)"
    native_compile_code=$?
    set -e
    if [[ "${native_compile_code}" -eq 0 ]] && grep -q 'helloworld_compile_smoke: compile OK' <<< "${compile_out}"; then
      M3_NATIVE_COMPILE=1
      M3_EMIT_PATH="native"
      M3_BLOCK_REASON=""
      echo "bootstrap-selfhost-helloworld-probe: native emit via selfhost compile driver OK"
    else
      if [[ "${BOOTSTRAP_M3_RUNTIME_COMPILE:-0}" != "1" ]]; then
        M3_BLOCK_REASON="runtime gate: BOOTSTRAP_M3_RUNTIME_COMPILE=1 not set (spine still stubbed in bundle)"
      elif grep -q 'native emit failed at phase=' <<< "${compile_out}"; then
        M3_BLOCK_REASON="$(grep -m1 'native emit failed at phase=' <<< "${compile_out}" | sed 's/^helloworld_compile_smoke: //')"
      elif grep -q 'emit path blocked' <<< "${compile_out}"; then
        M3_BLOCK_REASON="$(grep -m1 'emit path blocked' <<< "${compile_out}" | sed 's/^helloworld_compile_smoke: //')"
      else
        M3_BLOCK_REASON="native compile runtime failed ($(m3_exit_label "${native_compile_code}"))"
      fi
      echo "bootstrap-selfhost-helloworld-probe: native emit blocked — ${M3_BLOCK_REASON}" >&2
      printf '%s\n' "${compile_out}" >&2
    fi
  else
    M3_BLOCK_REASON="compile driver link failed ($(m3_exit_label "${m3_link_code}"), mode=${m3_link_mode})"
    echo "bootstrap-selfhost-helloworld-probe: ${M3_BLOCK_REASON}" >&2
  fi
fi

if [[ "${M3_NATIVE_COMPILE}" -eq 0 ]]; then
  echo "bootstrap-selfhost-helloworld-probe: native emit unavailable (M3 partial) — ${M3_BLOCK_REASON}" >&2
  echo "bootstrap-selfhost-helloworld-probe: NEXT_LOWER: Runtime ctor / loadJit / standalone off M3 deny list (#1402)" >&2
  if [[ "${BOOTSTRAP_M3_HELLOWORLD_STRICT:-0}" == "1" ]]; then
    echo "bootstrap-selfhost-helloworld-probe: BOOTSTRAP_M3_HELLOWORLD_STRICT=1 — require native emit; refusing Zend compile.php fallback" >&2
    echo "bootstrap-selfhost-helloworld-probe: emit_path=zend_fallback_would_be_used block_reason=${M3_BLOCK_REASON}" >&2
    exit 1
  fi
  M3_EMIT_PATH="zend"
  echo "bootstrap-selfhost-helloworld-probe: emit_path=zend (bin/compile.php) — HelloWorld AOT until native spine is ready" >&2
  rm -f "${AOT_OUT}"
  if ! php "${ROOT}/bin/compile.php" -o "${AOT_OUT}" "${SOURCE}" 2>&1; then
    echo "bootstrap-selfhost-helloworld-probe: Zend HelloWorld emit failed (emit_path=zend)" >&2
    exit 1
  fi
fi

if [[ ! -x "${AOT_OUT}" ]]; then
  echo "bootstrap-selfhost-helloworld-probe: missing executable ${AOT_OUT} (emit_path=${M3_EMIT_PATH})" >&2
  exit 1
fi

run_out="$("${AOT_OUT}" 2>&1)"
if ! grep -q 'Hello World' <<< "${run_out}"; then
  echo "bootstrap-selfhost-helloworld-probe: unexpected AOT stdout (want Hello World, emit_path=${M3_EMIT_PATH})" >&2
  printf '%s\n' "${run_out}" >&2
  exit 1
fi

if [[ "${M3_NATIVE_COMPILE}" -eq 1 ]]; then
  echo "bootstrap-selfhost-helloworld-probe: OK emit_path=native ${COMPILE_DRIVER} -> ${AOT_OUT}"
else
  echo "bootstrap-selfhost-helloworld-probe: OK emit_path=zend partial ${PROBE} -> ${AOT_OUT} (native run)"
fi
printf 'helloworld-aot stdout: %s\n' "${run_out}"
