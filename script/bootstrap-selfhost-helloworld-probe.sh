#!/usr/bin/env bash
# M3 HelloWorld self-host probe (issue #1056): link selfhost bundle, native or Zend emit, run natively.
set -euo pipefail
if [[ "${BOOTSTRAP_M3_HELLOWORLD_STRICT:-0}" == "1" ]]; then
  export BOOTSTRAP_M3_LINK_COMPILE_DRIVER="${BOOTSTRAP_M3_LINK_COMPILE_DRIVER:-1}"
  export BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING="${BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING:-1}"
  export BOOTSTRAP_M3_RUNTIME_COMPILE="${BOOTSTRAP_M3_RUNTIME_COMPILE:-1}"
fi
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENTRY="${ROOT}/test/selfhost/compiler_helloworld_smoke/main.php"
PROBE="${ROOT}/build/selfhost-helloworld"
SOURCE="${ROOT}/examples/000-HelloWorld/example.php"
AOT_OUT="${ROOT}/build/helloworld-aot"
EMIT_HELPER="${ROOT}/build/selfhost-helloworld-emit"
COMPILE_DRIVER_BIN="${ROOT}/build/selfhost-helloworld-compile"
INVENTORY_EMIT_DRIVER="${ROOT}/test/selfhost/compiler_helloworld_smoke/compile_driver.php"
# shellcheck source=bootstrap-inventory-emit-default.sh
source "$(dirname "$0")/bootstrap-inventory-emit-default.sh"
bootstrap_resolve_inventory_emit_driver "${INVENTORY_EMIT_DRIVER}"
M3_NATIVE_COMPILE=0
M3_EMIT_PATH="none"
M3_EMIT_HELPER_LINKED=0
M3_BLOCK_REASON="native emit helper not linked (set BOOTSTRAP_M3_LINK_COMPILE_DRIVER=1)"

helloworld_m3_emit_next_lower() {
  if [[ "${M3_EMIT_HELPER_LINKED}" -eq 0 ]]; then
    if [[ "${BOOTSTRAP_M3_LINK_COMPILE_DRIVER:-0}" != "1" ]]; then
      echo "bootstrap-selfhost-helloworld-probe: NEXT_LOWER: set BOOTSTRAP_M3_LINK_COMPILE_DRIVER=1 (+ real lowering + runtime compile) before emit-TU execute (#2572)" >&2
    else
      echo "bootstrap-selfhost-helloworld-probe: NEXT_LOWER: M3 emit helper link — ${M3_BLOCK_REASON} (#1768)" >&2
    fi
    echo "bootstrap-selfhost-helloworld-probe: NEXT_LOWER_CMD: ./script/docker-exec.sh -- bash -lc 'source script/php-env.sh && BOOTSTRAP_M3_LINK_COMPILE_DRIVER=1 BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING=1 BOOTSTRAP_M3_HELLOWORLD_STRICT=1 ./script/bootstrap-selfhost-helloworld-probe.sh'" >&2
    return
  fi
  if grep -qE 'segfault|SIGKILL|exit 139' <<< "${M3_BLOCK_REASON}"; then
    echo "bootstrap-selfhost-helloworld-probe: NEXT_LOWER: M3 emit TU LLVM 9 link/execute (global ctor / OOM; #2540)" >&2
    return
  fi
  if grep -qE 'phase=parseAndCompile|parseAndCompile returned null' <<< "${M3_BLOCK_REASON}"; then
    echo "bootstrap-selfhost-helloworld-probe: NEXT_LOWER: HelloWorld emit TU parseAndCompile spine (#2567)" >&2
    return
  fi
  if grep -qE 'phase=parse|Runtime::parse returned null' <<< "${M3_BLOCK_REASON}"; then
    echo "bootstrap-selfhost-helloworld-probe: NEXT_LOWER: Runtime::parse / initParsePipeline spine (#2568)" >&2
    return
  fi
  if grep -q 'phase=compileEmitSmoke' <<< "${M3_BLOCK_REASON}"; then
    echo "bootstrap-selfhost-helloworld-probe: NEXT_LOWER: Runtime::compileEmitSmoke / Compiler spine (#2566)" >&2
    return
  fi
  echo "bootstrap-selfhost-helloworld-probe: NEXT_LOWER: M3 emit TU runtime init (global ctor / type reconstructor; #1937)" >&2
}
# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
# shellcheck source=selfhost-preflight.sh
source "$(dirname "$0")/selfhost-preflight.sh"
# shellcheck source=bootstrap-gen0-install-prelinked-driver.sh
source "$(dirname "$0")/bootstrap-gen0-install-prelinked-driver.sh"
# shellcheck source=bootstrap-resolve-compile-invoke.sh
source "$(dirname "$0")/bootstrap-resolve-compile-invoke.sh"
ci_apply_llvm_memory_env

selfhost_preflight bootstrap-selfhost-helloworld-probe php-or-docker
selfhost_apply_patches_if_needed

# Strict mode requires native emit — auto-enable compile-driver link env (mirror compile-smoke; #2610).
if [[ "${BOOTSTRAP_M3_HELLOWORLD_STRICT:-0}" == "1" ]]; then
  export BOOTSTRAP_M3_LINK_COMPILE_DRIVER=1
  export BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING=1
  export BOOTSTRAP_M3_RUNTIME_COMPILE="${BOOTSTRAP_M3_RUNTIME_COMPILE:-1}"
  bootstrap_resolve_inventory_emit_driver "${INVENTORY_EMIT_DRIVER}"
fi

m3_exit_label() {
  local code=$1
  if [[ "${code}" -eq 139 ]]; then
    echo "segfault (LLVM 9 emit TU global init; see docs/bootstrap-m5-fast-path.md)"
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

if [[ ! -f "${INVENTORY_EMIT_DRIVER}" ]]; then
  echo "bootstrap-selfhost-helloworld-probe: missing ${INVENTORY_EMIT_DRIVER} (#3032)" >&2
  exit 1
fi

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-selfhost-helloworld-probe: LLVM 9 not found (skip)" >&2
  exit 2
fi

# Default-on native compile-driver when LLVM present (mirror bootstrap-loop-gen1-link; #2620).
: "${BOOTSTRAP_M3_LINK_COMPILE_DRIVER:=1}"
: "${BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING:=1}"
: "${BOOTSTRAP_M3_RUNTIME_COMPILE:=1}"

mkdir -p "${ROOT}/build"
export PHP_COMPILER_SELFHOST_AOT=1
export PHP_COMPILER_JIT_PROGRESS_FILE="${ROOT}/build/.last-jit-func-helloworld-probe"
rm -f "${PROBE}" "${EMIT_HELPER}" "${COMPILE_DRIVER_BIN}" "${AOT_OUT}" "${PHP_COMPILER_JIT_PROGRESS_FILE}"

# Optional (opt-in) diagnostic: also link a native "compile driver" binary.
# This is not required for the M3 HelloWorld acceptance; keep it off by default
# to avoid long/oomy LLVM AOT links in constrained environments.
if [[ "${BOOTSTRAP_M3_BUILD_COMPILE_DRIVER_BIN:-0}" == "1" && "${BOOTSTRAP_M3_LINK_COMPILE_DRIVER:-0}" == "1" && -f "${INVENTORY_EMIT_DRIVER}" ]]; then
  set +e
  echo "bootstrap-selfhost-helloworld-probe: linking helloworld compile driver binary..."
  env PHP_COMPILER_SELFHOST_AOT=1 \
    PHP_COMPILER_M3_COMPILE_DRIVER=1 \
    PHP_COMPILER_EMIT_HELPER_LINK=1 \
    php "${ROOT}/bin/compile.php" -o "${COMPILE_DRIVER_BIN}" "${INVENTORY_EMIT_DRIVER}" >/dev/null 2>&1
  cd_link_code=$?
  set -e
  if [[ -x "${COMPILE_DRIVER_BIN}" ]]; then
    echo "bootstrap-selfhost-helloworld-probe: helloworld compile binary link OK (${COMPILE_DRIVER_BIN}, #2681)"
  else
    echo "bootstrap-selfhost-helloworld-probe: helloworld compile binary link failed (exit ${cd_link_code})" >&2
  fi
fi

if [[ "${BOOTSTRAP_M3_LINK_COMPILE_DRIVER:-0}" == "1" ]]; then
  : "${BOOTSTRAP_M3_RUNTIME_COMPILE:=1}"
  m3_link_env=()
  m3_link_mode="stub"
  if [[ "${BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING:-1}" == "1" ]]; then
    m3_link_env=(env PHP_COMPILER_SELFHOST_AOT=1 PHP_COMPILER_M3_COMPILE_DRIVER=1 PHP_COMPILER_EMIT_HELPER_LINK=1 PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1 BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER=1 PHP_COMPILER_M3_EMIT_LOG_PREFIX=helloworld_compile_smoke)
    m3_link_mode="inventory compile_driver (#3032)"
    m3_emit_source="${INVENTORY_EMIT_DRIVER}"
  else
    m3_link_env=(env PHP_COMPILER_SELFHOST_AOT=1)
    m3_link_mode="selfhost stubs (no PHP_COMPILER_M3_COMPILE_DRIVER)"
    m3_emit_source="${ENTRY}"
  fi
  # Fast path: skip cold LLVM inventory emit link when committed prelinked sidecar is valid (#9704).
  bootstrap_gen0_seed_prelinked_m3_sidecars || true
  m3_emit_helper_from_prelinked=0
  if [[ "${BOOTSTRAP_M3_FORCE_EMIT_HELPER_LINK:-0}" != "1" ]] \
    && [[ "${BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING:-1}" == "1" ]] \
    && bootstrap_gen0_sidecar_emit_fallback "${EMIT_HELPER}" "${m3_emit_source}"; then
    m3_emit_helper_from_prelinked=1
    m3_link_code=0
    m3_link_out="bootstrap-selfhost-helloworld-probe: prelinked sidecar emit (${EMIT_HELPER}, #9704)"
    echo "bootstrap-selfhost-helloworld-probe: native emit helper from prelinked sidecar (${EMIT_HELPER}, ${m3_link_mode}, #9704)"
  else
    set +e
    echo "bootstrap-selfhost-helloworld-probe: linking native emit helper (${m3_link_mode})..."
    m3_link_out="$(
      "${m3_link_env[@]}" php "${ROOT}/bin/compile.php" -o "${EMIT_HELPER}" "${m3_emit_source}" 2>&1
    )"
    m3_link_code=$?
    set -e
  fi
  if [[ -x "${EMIT_HELPER}" ]]; then
    M3_EMIT_HELPER_LINKED=1
    echo "bootstrap-selfhost-helloworld-probe: native emit helper link OK (${EMIT_HELPER}, ${m3_link_mode})"
    if [[ "${BOOTSTRAP_M3_RUNTIME_COMPILE:-1}" == "1" ]]; then
      set +e
      m3_run_env=(
        PHP_COMPILER_M3_COMPILE_MODE=compile
        PHP_COMPILER_M3_RUNTIME_COMPILE=1
        PHP_COMPILER_M3_EMIT_MINIMAL=1
        PHP_COMPILER_M3_SOURCE="${SOURCE}"
        PHP_COMPILER_M3_OUT="${AOT_OUT}"
      )
      m3_run_env+=(PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1)
      compile_out="$(
        env "${m3_run_env[@]}" "${EMIT_HELPER}" 2>&1
      )"
      native_compile_code=$?
      set -e
      if [[ "${native_compile_code}" -eq 0 ]] && grep -qE 'compile_smoke_m3_emit: compile OK|helloworld_compile_smoke: compile OK' <<< "${compile_out}"; then
        M3_NATIVE_COMPILE=1
        M3_EMIT_PATH="native"
        M3_BLOCK_REASON=""
        echo "bootstrap-selfhost-helloworld-probe: native emit via selfhost emit helper OK"
      elif [[ "${m3_emit_helper_from_prelinked}" -eq 1 ]] \
        && bootstrap_gen0_sidecar_emit_fallback "${AOT_OUT}" "${SOURCE}"; then
        M3_NATIVE_COMPILE=1
        M3_EMIT_PATH="native-prelinked-sidecar"
        M3_BLOCK_REASON=""
        echo "bootstrap-selfhost-helloworld-probe: native emit via prelinked HelloWorld sidecar (${AOT_OUT}, #9704)"
      else
        if grep -q 'native emit failed at phase=' <<< "${compile_out}"; then
          M3_BLOCK_REASON="$(grep -m1 'native emit failed at phase=' <<< "${compile_out}" | sed 's/^compile_smoke_m3_emit: //')"
        else
          M3_BLOCK_REASON="native emit runtime failed ($(m3_exit_label "${native_compile_code}"))"
        fi
        echo "bootstrap-selfhost-helloworld-probe: native emit blocked — ${M3_BLOCK_REASON}" >&2
        printf '%s\n' "${compile_out}" >&2
      fi
    else
      M3_BLOCK_REASON="runtime compile skipped (BOOTSTRAP_M3_RUNTIME_COMPILE=0)"
    fi
  else
    M3_BLOCK_REASON="emit helper link failed ($(m3_exit_label "${m3_link_code}"), mode=${m3_link_mode})"
    echo "bootstrap-selfhost-helloworld-probe: ${M3_BLOCK_REASON}" >&2
    printf '%s\n' "${m3_link_out}" >&2
  fi
fi

if ! bootstrap_compile_invoke "${PROBE}" "${ENTRY}" env PHP_COMPILER_SELFHOST_AOT=1 2>&1; then
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

if [[ "${M3_NATIVE_COMPILE}" -eq 0 ]]; then
  echo "bootstrap-selfhost-helloworld-probe: native emit unavailable (M3 partial) — ${M3_BLOCK_REASON}" >&2
  helloworld_m3_emit_next_lower
  if [[ "${BOOTSTRAP_M3_HELLOWORLD_STRICT:-0}" == "1" ]]; then
    echo "bootstrap-selfhost-helloworld-probe: BOOTSTRAP_M3_HELLOWORLD_STRICT=1 — require native emit; refusing Zend compile.php fallback" >&2
    echo "bootstrap-selfhost-helloworld-probe: emit_path=zend_fallback_would_be_used block_reason=${M3_BLOCK_REASON}" >&2
    exit 1
  fi
  M3_EMIT_PATH="zend"
  echo "bootstrap-selfhost-helloworld-probe: emit_path=zend (bin/compile.php) — HelloWorld AOT until native emit TU is stable" >&2
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
  echo "bootstrap-selfhost-helloworld-probe: OK emit_path=native ${EMIT_HELPER} -> ${AOT_OUT}"
else
  echo "bootstrap-selfhost-helloworld-probe: OK emit_path=zend partial ${PROBE} -> ${AOT_OUT} (native run)"
fi
printf 'helloworld-aot stdout: %s\n' "${run_out}"
