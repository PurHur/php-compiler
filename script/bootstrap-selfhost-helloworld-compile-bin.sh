#!/usr/bin/env bash
# M5 repro: native compile via helloworld compile_driver (not emit-TU — #2681).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENTRY="${ROOT}/test/selfhost/compiler_helloworld_smoke/compile_driver.php"
OUT="${ROOT}/build/selfhost-helloworld-compile"
SOURCE="${PHP_COMPILER_M3_SOURCE:-${ROOT}/examples/000-HelloWorld/example.php}"
AOT_OUT="${PHP_COMPILER_M3_OUT:-${ROOT}/build/helloworld-compile-bin-aot}"
if [[ "${AOT_OUT}" != /* ]]; then
  AOT_OUT="${ROOT}/${AOT_OUT#./}"
fi
# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
# shellcheck source=bootstrap-gen0-install-prelinked-driver.sh
source "$(dirname "$0")/bootstrap-gen0-install-prelinked-driver.sh"
ci_apply_llvm_memory_env

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-selfhost-helloworld-compile-bin: LLVM 9 not found (skip)" >&2
  exit 2
fi

if [[ ! -f "${ENTRY}" ]]; then
  echo "bootstrap-selfhost-helloworld-compile-bin: missing ${ENTRY}" >&2
  exit 1
fi

mkdir -p "${ROOT}/build"
export PHP_COMPILER_SELFHOST_AOT=1
export PHP_COMPILER_M3_COMPILE_DRIVER=1
export PHP_COMPILER_EMIT_HELPER_LINK=1

SOURCE_NORM="${SOURCE}"
if [[ "${SOURCE}" != /* ]]; then
  SOURCE_NORM="${ROOT}/${SOURCE#./}"
fi

# M5/M4: native bin/compile.php argv driver — inventory Compiler {main}, not emit-TU (#2900, #2880).
if [[ "${SOURCE_NORM}" == "${ROOT}/bin/compile.php" ]]; then
  EMIT_HELPER="${ROOT}/build/selfhost-native-compile-driver"
  INVENTORY_ARGV="${ROOT}/build/bin-compile-aot-inventory"
  PRELINKED_GEN0="$(bootstrap_gen0_prelinked_driver_path)"
  # Zend inventory emit SIGSEGV on bin/compile.php — prefer committed gen-0 when unset (#2930).
  if [[ "${BOOTSTRAP_INVENTORY_DRIVER_USE_PRELINKED:-}" == "" && bootstrap_gen0_prelinked_driver_ready ]]; then
    BOOTSTRAP_INVENTORY_DRIVER_USE_PRELINKED=1
  fi
  rm -f "${EMIT_HELPER}" "${ROOT}/build/.last-jit-func-native-compile-driver"
  if [[ "${AOT_OUT}" != "${INVENTORY_ARGV}" ]]; then
    rm -f "${AOT_OUT}" "${INVENTORY_ARGV}"
  else
    rm -f "${AOT_OUT}"
  fi
  export PHP_COMPILER_JIT_PROGRESS_FILE="${ROOT}/build/.last-jit-func-native-compile-driver"
  _inventory_zend_ok=0
  if [[ "${BOOTSTRAP_INVENTORY_DRIVER_USE_PRELINKED:-0}" != "1" ]]; then
    bootstrap_gen0_seed_prelinked_m3_sidecars || true
    _inventory_minimal=0
    _inventory_full=0
    if [[ "${BOOTSTRAP_INVENTORY_DRIVER_FULL:-0}" == "1" ]]; then
      _inventory_full=1
    elif [[ "${BOOTSTRAP_INVENTORY_MINIMAL_SIDECARS:-1}" == "1" ]]; then
      _inventory_minimal=1
    fi
    set +e
    _inventory_zend_out="$(
      env -u PHP_COMPILER_EMIT_HELPER_LINK PHP_COMPILER_SELFHOST_AOT=1 PHP_COMPILER_M3_COMPILE_DRIVER=1 \
        PHP_COMPILER_M3_COMPILE_DRIVER_MAIN=1 PHP_COMPILER_M4_BIN_COMPILE_DRIVER=1 \
        PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1 BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER=1 \
        PHP_COMPILER_M3_EMIT_LOG_PREFIX=helloworld_compile_smoke \
        PHP_COMPILER_M3_INVENTORY_MINIMAL_SIDECARS="${_inventory_minimal}" \
        PHP_COMPILER_M3_REUSE_STALE_COMPILER_LIB_SIDECAR="${_inventory_minimal}" \
        php "${ROOT}/bin/compile.php" -o "${AOT_OUT}" "${ROOT}/bin/compile.php" 2>&1
    )"
    _inventory_zend_code=$?
    set -e
    printf '%s\n' "${_inventory_zend_out}"
    if [[ "${_inventory_zend_code}" -eq 0 && -x "${AOT_OUT}" ]]; then
      _inventory_zend_ok=1
    elif [[ "${_inventory_zend_code}" -eq 139 ]]; then
      echo "bootstrap-selfhost-helloworld-compile-bin: Zend inventory emit segfault (exit 139); trying prelinked gen-0 (#2930)" >&2
    else
      echo "bootstrap-selfhost-helloworld-compile-bin: Zend inventory emit failed (exit ${_inventory_zend_code}); trying prelinked gen-0 (#2930)" >&2
    fi
  fi
  if [[ "${_inventory_zend_ok}" -eq 0 ]]; then
    if ! bootstrap_gen0_copy_prelinked_inventory_driver "${AOT_OUT}" "${EMIT_HELPER}" "${INVENTORY_ARGV}"; then
      echo "bootstrap-selfhost-helloworld-compile-bin: native compile driver link failed (no prelinked ${PRELINKED_GEN0})" >&2
      exit 1
    fi
    echo "bootstrap-selfhost-helloworld-compile-bin: OK ${AOT_OUT} (prelinked inventory argv driver; SSOT ${INVENTORY_ARGV}; #2930)"
    exit 0
  fi
  if [[ -n "${EMIT_HELPER}" && "${EMIT_HELPER}" != "${AOT_OUT}" ]]; then
    cp -f "${AOT_OUT}" "${EMIT_HELPER}"
    chmod +x "${EMIT_HELPER}"
  fi
  if [[ "${ROOT}/build/.m3_bin_compile_aot_blob" != "${AOT_OUT}" ]]; then
    cp -f "${AOT_OUT}" "${ROOT}/build/.m3_bin_compile_aot_blob"
    chmod +x "${ROOT}/build/.m3_bin_compile_aot_blob"
  fi
  if [[ -n "${INVENTORY_ARGV}" && "${INVENTORY_ARGV}" != "${AOT_OUT}" ]]; then
    cp -f "${AOT_OUT}" "${INVENTORY_ARGV}"
    chmod +x "${INVENTORY_ARGV}"
  fi
  chmod +x "${AOT_OUT}"
  echo "bootstrap-selfhost-helloworld-compile-bin: OK ${AOT_OUT} (inventory bin/compile.php argv driver; SSOT ${INVENTORY_ARGV})"
  exit 0
fi

export PHP_COMPILER_JIT_PROGRESS_FILE="${ROOT}/build/.last-jit-func-helloworld-compile-bin"
rm -f "${OUT}" "${AOT_OUT}" "${PHP_COMPILER_JIT_PROGRESS_FILE}"

set +e
link_out="$(
  env PHP_COMPILER_SELFHOST_AOT=1 \
    PHP_COMPILER_M3_COMPILE_DRIVER=1 \
    PHP_COMPILER_EMIT_HELPER_LINK=1 \
    PHP_COMPILER_M3_COMPILE_DRIVER_MAIN=1 \
    PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1 \
    BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER=1 \
    PHP_COMPILER_M3_EMIT_LOG_PREFIX=helloworld_compile_smoke \
    php "${ROOT}/bin/compile.php" -o "${OUT}" "${ENTRY}" 2>&1
)"
link_code=$?
set -e
if [[ ! -x "${OUT}" ]]; then
  echo "bootstrap-selfhost-helloworld-compile-bin: link failed (exit ${link_code})" >&2
  printf '%s\n' "${link_out}" >&2
  exit 1
fi
echo "bootstrap-selfhost-helloworld-compile-bin: link OK ${OUT}"

set +e
compile_out="$(
  env PHP_COMPILER_M3_COMPILE_MODE=compile \
    PHP_COMPILER_M3_RUNTIME_COMPILE=1 \
    PHP_COMPILER_M3_SOURCE="${SOURCE}" \
    PHP_COMPILER_M3_OUT="${AOT_OUT}" \
    "${OUT}" 2>&1
)"
compile_code=$?
set -e
printf '%s\n' "${compile_out}"

if [[ "${compile_code}" -eq 0 ]] && grep -qE 'helloworld_compile_smoke: compile OK|compile_smoke_m3_emit: compile OK' <<< "${compile_out}"; then
  echo "bootstrap-selfhost-helloworld-compile-bin: OK ${OUT} -> ${AOT_OUT}"
  # M5 gen-2 argv driver must be inventory-linked bin/compile.php (~400KiB), not HelloWorld-only (~180KiB) (#3011).
  BIN_COMPILE_AOT="${ROOT}/build/bin-compile-aot-inventory"
  rm -f "${BIN_COMPILE_AOT}"
  if [[ "${BOOTSTRAP_INVENTORY_DRIVER_USE_PRELINKED:-}" == "" && bootstrap_gen0_prelinked_driver_ready ]]; then
    BOOTSTRAP_INVENTORY_DRIVER_USE_PRELINKED=1
  fi
  _inventory_argv_ok=0
  if [[ "${BOOTSTRAP_INVENTORY_DRIVER_USE_PRELINKED:-0}" != "1" ]]; then
    bootstrap_gen0_seed_prelinked_m3_sidecars || true
    set +e
    _inventory_argv_out="$(
      env -u PHP_COMPILER_EMIT_HELPER_LINK PHP_COMPILER_SELFHOST_AOT=1 PHP_COMPILER_M3_COMPILE_DRIVER=1 \
        PHP_COMPILER_M3_COMPILE_DRIVER_MAIN=1 PHP_COMPILER_M4_BIN_COMPILE_DRIVER=1 \
        PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1 BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER=1 \
        PHP_COMPILER_M3_EMIT_LOG_PREFIX=helloworld_compile_smoke \
        php "${ROOT}/bin/compile.php" -o "${BIN_COMPILE_AOT}" "${ROOT}/bin/compile.php" 2>&1
    )"
    _inventory_argv_code=$?
    set -e
    printf '%s\n' "${_inventory_argv_out}"
    if [[ "${_inventory_argv_code}" -eq 0 && -x "${BIN_COMPILE_AOT}" ]]; then
      _inventory_argv_ok=1
    else
      echo "bootstrap-selfhost-helloworld-compile-bin: Zend inventory argv failed (exit ${_inventory_argv_code}); trying prelinked gen-0 (#2930)" >&2
    fi
  fi
  if [[ "${_inventory_argv_ok}" -eq 0 ]]; then
    if ! bootstrap_gen0_copy_prelinked_inventory_driver "${BIN_COMPILE_AOT}" "" "${BIN_COMPILE_AOT}"; then
      echo "bootstrap-selfhost-helloworld-compile-bin: inventory bin/compile.php argv driver failed (#3011)" >&2
      exit 1
    fi
    echo "bootstrap-selfhost-helloworld-compile-bin: OK inventory argv ${BIN_COMPILE_AOT} (prelinked; #2930)"
    exit 0
  fi
  cp -f "${BIN_COMPILE_AOT}" "${ROOT}/build/.m3_bin_compile_aot_blob"
  chmod +x "${BIN_COMPILE_AOT}" "${ROOT}/build/.m3_bin_compile_aot_blob"
  echo "bootstrap-selfhost-helloworld-compile-bin: OK inventory argv ${BIN_COMPILE_AOT} (#3011)"
  exit 0
fi

if grep -q 'helloworld_compile_smoke:' <<< "${compile_out}"; then
  echo "bootstrap-selfhost-helloworld-compile-bin: compile blocked (honest helloworld_compile_smoke prefix — not emit-TU)" >&2
  exit 1
fi

echo "bootstrap-selfhost-helloworld-compile-bin: unexpected output (want helloworld_compile_smoke: prefix)" >&2
exit 1
