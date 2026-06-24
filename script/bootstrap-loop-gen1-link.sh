#!/usr/bin/env bash
# M4 gen-1 link + gen-2 compile attempt (issue #1498): bootstrap_loop_smoke bundle → gen-2 smoke binary.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "${ROOT}"
ENTRY="test/selfhost/bootstrap_loop_smoke/main.php"
GEN1="build/bootstrap-loop-gen1"
EMIT_HELPER="build/bootstrap-loop-gen1-compile"
# bootstrap_loop_smoke/compile_driver.php delegates here; link helloworld bundle directly (#2893).
INVENTORY_EMIT_DRIVER="${ROOT}/test/selfhost/compiler_helloworld_smoke/compile_driver.php"
# shellcheck source=bootstrap-inventory-emit-default.sh
source "$(dirname "$0")/bootstrap-inventory-emit-default.sh"
bootstrap_resolve_inventory_emit_driver "${INVENTORY_EMIT_DRIVER}"
GEN2_SOURCE="test/bootstrap-aot/compiler_smoke_standalone.php"
GEN2_OUT="build/bootstrap-loop-gen2"
GEN2_EXPECT_STDOUT_RE="compiler smoke"
M4_NATIVE_COMPILE=0
M4_EMIT_PATH="none"
M4_BLOCK_REASON="native compile driver not linked (set BOOTSTRAP_M4_LINK_COMPILE_DRIVER=1)"
# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
# shellcheck source=selfhost-preflight.sh
source "$(dirname "$0")/selfhost-preflight.sh"
# shellcheck source=bootstrap-resolve-compile-invoke.sh
source "$(dirname "$0")/bootstrap-resolve-compile-invoke.sh"
# shellcheck source=bootstrap-gen0-install-prelinked-driver.sh
source "$(dirname "$0")/bootstrap-gen0-install-prelinked-driver.sh"
ci_apply_llvm_memory_env
selfhost_apply_patches_if_needed

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

# Optional: compile inventory-scale spine bundle instead of compile-smoke slice (#2664).
if [[ "${BOOTSTRAP_M4_GEN1_COMPILE_FULL_SPINE:-0}" == "1" ]]; then
  GEN2_SOURCE="test/selfhost/compiler_lib_spine_smoke/main.php"
  GEN2_OUT="build/bootstrap-loop-gen2-full-spine"
  GEN2_EXPECT_STDOUT_RE="compiler_lib_spine_smoke bundle OK"
  if [[ ! -f "${GEN2_SOURCE}" ]]; then
    echo "bootstrap-loop-gen1-link: BOOTSTRAP_M4_GEN1_COMPILE_FULL_SPINE=1 but missing ${ROOT}/${GEN2_SOURCE}" >&2
    exit 1
  fi
fi

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-loop-gen1-link: LLVM 9 not found (skip)" >&2
  exit 2
fi

# Default-on native compile-driver when LLVM present (mirror make bootstrap-loop-gen1-link; #2611).
# Strict requires native gen-2 emit — default-on (#8711); opt-out BOOTSTRAP_M4_GEN2_STRICT=0.
: "${BOOTSTRAP_M4_GEN2_STRICT:=1}"
if [[ "${BOOTSTRAP_M4_GEN2_STRICT}" == "1" ]]; then
  export BOOTSTRAP_M4_LINK_COMPILE_DRIVER=1
  export BOOTSTRAP_M4_COMPILE_DRIVER_REAL_LOWERING=1
  export BOOTSTRAP_M4_RUNTIME_COMPILE=1
else
  : "${BOOTSTRAP_M4_LINK_COMPILE_DRIVER:=1}"
  : "${BOOTSTRAP_M4_COMPILE_DRIVER_REAL_LOWERING:=1}"
  : "${BOOTSTRAP_M4_RUNTIME_COMPILE:=1}"
fi

mkdir -p build
export PHP_COMPILER_SELFHOST_AOT=1
export PHP_COMPILER_JIT_PROGRESS_FILE="build/.last-jit-func-bootstrap-loop-gen1"
rm -f "${GEN1}" "${EMIT_HELPER}" "${GEN2_OUT}" "${PHP_COMPILER_JIT_PROGRESS_FILE}"

if [[ ! -f "${INVENTORY_EMIT_DRIVER}" ]]; then
  echo "bootstrap-loop-gen1-link: missing ${INVENTORY_EMIT_DRIVER} (#3032)" >&2
  exit 1
fi

if [[ "${BOOTSTRAP_M4_LINK_COMPILE_DRIVER:-0}" == "1" ]]; then
  # Native emit execute defaults on with compile-driver link (#2599); set =0 to bisect Zend fallback.
  : "${BOOTSTRAP_M4_RUNTIME_COMPILE:=1}"
  m4_link_env=()
  m4_link_mode="stub"
  if [[ "${BOOTSTRAP_M4_COMPILE_DRIVER_REAL_LOWERING:-${BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING:-1}}" == "1" ]]; then
    # Zend inventory emit link — mirror M3 helloworld probe / helloworld-compile-bin (#3032).
    # Post-M2 inventory argv driver (bin-compile-aot-inventory) can bake stub "ready" {main}
    # when invoked via bootstrap_compile_invoke; bootstrap-loop-probe then exits 2 (#1498).
    m4_link_env=(env PHP_COMPILER_SELFHOST_AOT=1 PHP_COMPILER_M3_COMPILE_DRIVER=1 PHP_COMPILER_EMIT_HELPER_LINK=1 PHP_COMPILER_M3_COMPILE_DRIVER_MAIN=1 PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1 BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER=1 PHP_COMPILER_M3_EMIT_LOG_PREFIX=helloworld_compile_smoke)
    m4_link_mode="inventory compile_driver (#3032)"
    m4_emit_entry="${INVENTORY_EMIT_DRIVER}"
  else
    m4_link_env=(env PHP_COMPILER_SELFHOST_AOT=1)
    m4_link_mode="selfhost stubs (no PHP_COMPILER_M3_COMPILE_DRIVER)"
  fi
  echo "==> link gen-1 emit helper (inventory compile_driver by default, #3032; BOOTSTRAP_M3_EMIT_HELPER_TU=1 for thin emit TU bisect)"
  rm -f "${EMIT_HELPER}" "build/.last-jit-func-bootstrap-loop-gen1-emit"
  export PHP_COMPILER_JIT_PROGRESS_FILE="build/.last-jit-func-bootstrap-loop-gen1-emit"
  # Fast path: skip cold LLVM inventory emit link when committed prelinked sidecar is valid (#9704, mirror M3 probe).
  bootstrap_gen0_seed_prelinked_m3_sidecars || true
  m4_emit_helper_from_prelinked=0
  set +e
  if [[ "${BOOTSTRAP_M4_FORCE_EMIT_HELPER_LINK:-0}" != "1" ]] \
    && [[ "${BOOTSTRAP_M4_COMPILE_DRIVER_REAL_LOWERING:-${BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING:-1}}" == "1" ]] \
    && bootstrap_gen0_sidecar_emit_fallback "${EMIT_HELPER}" "${m4_emit_entry}"; then
    m4_emit_helper_from_prelinked=1
    emit_link_code=0
    emit_link_out="bootstrap-loop-gen1-link: prelinked sidecar emit (${EMIT_HELPER}, #9704)"
    echo "bootstrap-loop-gen1-link: native emit helper from prelinked sidecar (${EMIT_HELPER}, ${m4_link_mode}, #9704)"
  elif [[ "${BOOTSTRAP_M4_COMPILE_DRIVER_REAL_LOWERING:-${BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING:-1}}" == "1" ]]; then
    emit_link_out="$(
      "${m4_link_env[@]}" php "${ROOT}/bin/compile.php" -o "${EMIT_HELPER}" "${m4_emit_entry}" 2>&1
    )"
    emit_link_code=$?
  else
    emit_link_out="$(
      bootstrap_compile_invoke "${EMIT_HELPER}" "${m4_emit_entry}" "${m4_link_env[@]}" 2>&1
    )"
    emit_link_code=$?
  fi
  if [[ ! -x "${EMIT_HELPER}" ]] \
    && bootstrap_try_sidecar_emit_fallback "${EMIT_HELPER}" "${m4_emit_entry}" "${emit_link_code}"; then
    m4_emit_helper_from_prelinked=1
    emit_link_code=0
    echo "bootstrap-loop-gen1-link: native emit helper from prelinked sidecar after link failure (${EMIT_HELPER}, ${m4_link_mode}, #9704)"
  fi
  set -e
  if [[ -x "${EMIT_HELPER}" ]]; then
    echo "bootstrap-loop-gen1-link: emit helper link OK (${ROOT}/${EMIT_HELPER}, ${m4_link_mode})"
    if [[ "${BOOTSTRAP_M4_RUNTIME_COMPILE:-1}" == "1" ]]; then
      set +e
      m4_run_env=(PHP_COMPILER_M3_COMPILE_MODE=compile PHP_COMPILER_M3_RUNTIME_COMPILE=1 PHP_COMPILER_M3_EMIT_MINIMAL=1 PHP_COMPILER_M3_SOURCE="${ROOT}/${GEN2_SOURCE}" PHP_COMPILER_M3_OUT="${ROOT}/${GEN2_OUT}")
      m4_run_env+=(PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1)
      compile_out="$(
        env "${m4_run_env[@]}" "./${EMIT_HELPER}" 2>&1
      )"
      native_compile_code=$?
      set -e
      if [[ "${native_compile_code}" -eq 0 ]] && grep -qE 'compile_smoke_m3_emit: compile OK|helloworld_compile_smoke: compile OK|bootstrap_loop_compile_smoke: gen-2 compile OK' <<< "${compile_out}"; then
        M4_NATIVE_COMPILE=1
        M4_EMIT_PATH="native"
        M4_BLOCK_REASON=""
        echo "bootstrap-loop-gen1-link: gen-1 native emit OK (${ROOT}/${EMIT_HELPER} -> ${ROOT}/${GEN2_OUT})"
      elif [[ "${m4_emit_helper_from_prelinked}" -eq 1 ]] \
        && bootstrap_gen0_sidecar_emit_fallback "${GEN2_OUT}" "${ROOT}/${GEN2_SOURCE}"; then
        M4_NATIVE_COMPILE=1
        M4_EMIT_PATH="native-prelinked-sidecar"
        M4_BLOCK_REASON=""
        echo "bootstrap-loop-gen1-link: gen-2 native emit via prelinked sidecar (${GEN2_OUT}, #9704)"
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
      M4_BLOCK_REASON="runtime compile skipped (BOOTSTRAP_M4_RUNTIME_COMPILE=0)"
    fi
  else
    M4_BLOCK_REASON="emit helper link failed ($(m4_gen_exit_label "${emit_link_code}"), mode=${m4_link_mode})"
    echo "bootstrap-loop-gen1-link: ${M4_BLOCK_REASON}" >&2
  fi
  export PHP_COMPILER_JIT_PROGRESS_FILE="build/.last-jit-func-bootstrap-loop-gen1"
fi

echo "==> link gen-1 (bootstrap_loop_smoke bundle)"
if ! bootstrap_compile_invoke "${GEN1}" "${ROOT}/${ENTRY}" env PHP_COMPILER_SELFHOST_AOT=1 2>&1; then
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
  if [[ "${BOOTSTRAP_M4_GEN2_STRICT}" == "1" && "${BOOTSTRAP_M4_GEN2_ZEND_FALLBACK:-0}" != "1" ]]; then
    echo "bootstrap-loop-gen1-link: BOOTSTRAP_M4_GEN2_STRICT=1 — require native gen-2 emit; refusing Zend fallback (opt-in: BOOTSTRAP_M4_GEN2_ZEND_FALLBACK=1)" >&2
    echo "bootstrap-loop-gen1-link: emit_path=zend_fallback_would_be_used block_reason=${M4_BLOCK_REASON}" >&2
    exit 1
  fi
  M4_EMIT_PATH="zend"
  echo "bootstrap-loop-gen1-link: gen-2 emit_path=zend (bin/compile.php) — ${M4_BLOCK_REASON}" >&2
  rm -f "${GEN2_OUT}"
  if ! bootstrap_compile_invoke "${GEN2_OUT}" "${ROOT}/${GEN2_SOURCE}" env PHP_COMPILER_SELFHOST_AOT=1 2>&1; then
    echo "bootstrap-loop-gen1-link: gen-2 emit failed (compiled driver or Zend)" >&2
    exit 1
  fi
fi

if [[ ! -x "${GEN2_OUT}" ]]; then
  echo "bootstrap-loop-gen1-link: missing gen-2 executable ${ROOT}/${GEN2_OUT} (emit_path=${M4_EMIT_PATH})" >&2
  exit 1
fi

gen2_out="$("./${GEN2_OUT}" 2>&1)"
if ! grep -qE "${GEN2_EXPECT_STDOUT_RE}" <<< "${gen2_out}"; then
  echo "bootstrap-loop-gen1-link: unexpected gen-2 stdout (want ${GEN2_EXPECT_STDOUT_RE}, emit_path=${M4_EMIT_PATH})" >&2
  printf '%s\n' "${gen2_out}" >&2
  exit 1
fi

if [[ "${M4_NATIVE_COMPILE}" -eq 1 ]]; then
  echo "bootstrap-loop-gen1-link: OK emit_path=${M4_EMIT_PATH} gen-1=${ROOT}/${GEN1} gen-2=${ROOT}/${GEN2_OUT}"
else
  echo "bootstrap-loop-gen1-link: OK emit_path=zend partial gen-1=${ROOT}/${GEN1} gen-2=${ROOT}/${GEN2_OUT} (gen-1 native compile blocked)"
fi
printf 'gen-2 stdout: %s\n' "${gen2_out}"
