#!/usr/bin/env bash
# M5 repro: native compile via helloworld compile_driver (not emit-TU — #2681).
# Inventory argv driver: compiled-first via bootstrap_inventory_argv_link (#2930, #3053).
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
# shellcheck source=bootstrap-resolve-compile-invoke.sh
source "$(dirname "$0")/bootstrap-resolve-compile-invoke.sh"
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

bootstrap_finalize_inventory_argv_outputs() {
  local aot_out=$1
  local emit_helper="${2:-}"
  local inventory_argv="${3:-}"
  if [[ -n "${emit_helper}" && "${emit_helper}" != "${aot_out}" && -x "${aot_out}" ]]; then
    cp -f "${aot_out}" "${emit_helper}"
    chmod +x "${emit_helper}"
  fi
  if [[ -x "${aot_out}" ]]; then
    if [[ "${ROOT}/build/.m3_bin_compile_aot_blob" != "${aot_out}" ]]; then
      cp -f "${aot_out}" "${ROOT}/build/.m3_bin_compile_aot_blob"
      chmod +x "${ROOT}/build/.m3_bin_compile_aot_blob"
    fi
    if [[ -n "${inventory_argv}" && "${inventory_argv}" != "${aot_out}" ]]; then
      cp -f "${aot_out}" "${inventory_argv}"
      chmod +x "${inventory_argv}"
    fi
    chmod +x "${aot_out}"
  fi
}

# M5/M4: native bin/compile.php argv driver — inventory Compiler {main}, not emit-TU (#2900, #2880).
if [[ "${SOURCE_NORM}" == "${ROOT}/bin/compile.php" ]]; then
  EMIT_HELPER="${ROOT}/build/selfhost-native-compile-driver"
  INVENTORY_ARGV="${ROOT}/build/bin-compile-aot-inventory"
  rm -f "${EMIT_HELPER}" "${ROOT}/build/.last-jit-func-native-compile-driver"
  if [[ "${AOT_OUT}" != "${INVENTORY_ARGV}" ]]; then
    rm -f "${AOT_OUT}" "${INVENTORY_ARGV}"
  else
    rm -f "${AOT_OUT}"
  fi
  export PHP_COMPILER_JIT_PROGRESS_FILE="${ROOT}/build/.last-jit-func-native-compile-driver"
  if ! bootstrap_inventory_argv_link "${AOT_OUT}"; then
    echo "bootstrap-selfhost-helloworld-compile-bin: inventory argv link failed (#2930)" >&2
    exit 1
  fi
  bootstrap_finalize_inventory_argv_outputs "${AOT_OUT}" "${EMIT_HELPER}" "${INVENTORY_ARGV}"
  echo "bootstrap-selfhost-helloworld-compile-bin: OK ${AOT_OUT} (inventory bin/compile.php argv driver; SSOT ${INVENTORY_ARGV})"
  exit 0
fi

export PHP_COMPILER_JIT_PROGRESS_FILE="${ROOT}/build/.last-jit-func-helloworld-compile-bin"
rm -f "${OUT}" "${AOT_OUT}" "${PHP_COMPILER_JIT_PROGRESS_FILE}"

bootstrap_inventory_argv_link_sidecar_prep 2>/dev/null || true
if ! bootstrap_compile_invoke "${OUT}" "${ENTRY}" env \
  PHP_COMPILER_SELFHOST_AOT=1 \
  PHP_COMPILER_M3_COMPILE_DRIVER=1 \
  PHP_COMPILER_EMIT_HELPER_LINK=1 \
  PHP_COMPILER_M3_COMPILE_DRIVER_MAIN=1 \
  PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1 \
  BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER=1 \
  PHP_COMPILER_M3_EMIT_LOG_PREFIX=helloworld_compile_smoke; then
  echo "bootstrap-selfhost-helloworld-compile-bin: compile driver link failed" >&2
  exit 1
fi
echo "bootstrap-selfhost-helloworld-compile-bin: link OK ${OUT} (gen-0 ${BOOTSTRAP_COMPILE_DRIVER_MODE:-compiled})"

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
  BIN_COMPILE_AOT="${ROOT}/build/bin-compile-aot-inventory"
  rm -f "${BIN_COMPILE_AOT}"
  if ! bootstrap_inventory_argv_link "${BIN_COMPILE_AOT}"; then
    echo "bootstrap-selfhost-helloworld-compile-bin: inventory bin/compile.php argv driver failed (#3011)" >&2
    exit 1
  fi
  bootstrap_finalize_inventory_argv_outputs "${BIN_COMPILE_AOT}" "" "${BIN_COMPILE_AOT}"
  echo "bootstrap-selfhost-helloworld-compile-bin: OK inventory argv ${BIN_COMPILE_AOT} (#3011)"
  exit 0
fi

if grep -q 'helloworld_compile_smoke:' <<< "${compile_out}"; then
  echo "bootstrap-selfhost-helloworld-compile-bin: compile blocked (honest helloworld_compile_smoke prefix — not emit-TU)" >&2
  exit 1
fi

echo "bootstrap-selfhost-helloworld-compile-bin: unexpected output (want helloworld_compile_smoke: prefix)" >&2
exit 1
