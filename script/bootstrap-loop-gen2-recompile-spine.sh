#!/usr/bin/env bash
# M4/M5: native driver recompiles full compiler spine — issue #1498, #2697, #2866.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "${ROOT}"

# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
# shellcheck source=bootstrap-resolve-compile-invoke.sh
source "$(dirname "$0")/bootstrap-resolve-compile-invoke.sh"
# shellcheck source=bootstrap-gen0-install-prelinked-driver.sh
source "$(dirname "$0")/bootstrap-gen0-install-prelinked-driver.sh"
ci_apply_llvm_memory_env
ci_ensure_vendor_patches

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-loop-gen2-recompile-spine: LLVM 9 not found (skip)" >&2
  exit 2
fi

DRIVER="${ROOT}/build/bin-compile-aot"
GEN3="${ROOT}/build/bootstrap-loop-gen3-full-spine"
SOURCE="${ROOT}/test/selfhost/compiler_lib_spine_smoke/main.php"

print_segfault_context() {
  local code=$1
  local driver=$2
  local gen3=$3
  local source=$4

  if [[ "${code}" -ne 139 ]]; then
    return 0
  fi

  echo "bootstrap-loop-gen2-recompile-spine: segfault (exit 139) while running: ${driver} -o ${gen3} ${source}" >&2
  echo "bootstrap-loop-gen2-recompile-spine: re-running once with core dumps + last-phase breadcrumbs enabled (#2939)" >&2

  local log="${ROOT}/build/bootstrap-loop-gen2-recompile-spine.segfault.log"
  local phase="${ROOT}/build/last_lowering_phase.json"

  rm -f "${log}" "${phase}" "${ROOT}/build/core" "${ROOT}/build/core."* "${ROOT}/build/core_"* || true
  ( ulimit -c unlimited && cd "${ROOT}/build" && \
    env \
      PHP_COMPILER_DEBUG_LAST_PHASE=1 \
      PHP_COMPILER_DEBUG_LAST_PHASE_FILE="${phase}" \
      PHP_COMPILER_DEBUG_LAST_PHASE_STDERR=1 \
      -u PHP_COMPILER_M3_SOURCE -u PHP_COMPILER_M3_OUT \
      "${driver}" -o "${gen3}" "${source}" >"${log}" 2>&1
  ) || true

  if [[ -f "${phase}" ]]; then
    echo "bootstrap-loop-gen2-recompile-spine: last lowering phase JSON: ${phase}" >&2
    # Keep the stderr readable even if JSON grows.
    head -n 120 "${phase}" >&2 || true
  else
    echo "bootstrap-loop-gen2-recompile-spine: no last-phase JSON written (set BOOTSTRAP_DEBUG=1 or run with bash -x to keep breadcrumbs enabled)" >&2
  fi

  local core=""
  if [[ -f "${ROOT}/build/core" ]]; then
    core="${ROOT}/build/core"
  else
    core="$(ls -1 "${ROOT}/build"/core.* "${ROOT}/build"/core_* 2>/dev/null | head -n 1 || true)"
  fi
  if [[ -n "${core}" && -f "${core}" ]]; then
    echo "bootstrap-loop-gen2-recompile-spine: core dump saved: ${core}" >&2
    echo "bootstrap-loop-gen2-recompile-spine: to inspect (inside docker-exec shell): apt-get update && apt-get install -y gdb && gdb -q \"${driver}\" \"${core}\" -ex bt -ex quit" >&2
  else
    echo "bootstrap-loop-gen2-recompile-spine: no core dump found under build/ (kernel core_pattern may redirect to coredumpctl)" >&2
  fi

  echo "bootstrap-loop-gen2-recompile-spine: segfault rerun log: ${log}" >&2
}

# M3 compiler_lib spine sidecar must match test/selfhost/compiler_lib_spine_smoke/main.php (#3012).
bootstrap_ensure_m3_compiler_lib_sidecar 2>/dev/null || true
export PHP_COMPILER_SELFHOST_AOT=1

# Compiled-first: seed gen-0 only when no working native driver under build/ (#2930, #3053).
PRELINKED_GEN0="${ROOT}/prelinked/bootstrap-gen0/bin-compile-aot"
INVENTORY_DRIVER="${ROOT}/build/bin-compile-aot-inventory"
if [[ "${BOOTSTRAP_LOOP_USE_EXISTING_BIN_COMPILE_AOT:-}" == "" ]]; then
  if [[ -x "${INVENTORY_DRIVER}" ]] \
    && bootstrap_inventory_argv_driver_smoke "${INVENTORY_DRIVER}" 2>/dev/null; then
    BOOTSTRAP_LOOP_USE_EXISTING_BIN_COMPILE_AOT=1
  elif [[ -x "${DRIVER}" ]] \
    && bootstrap_inventory_argv_driver_smoke "${DRIVER}" 2>/dev/null; then
    BOOTSTRAP_LOOP_USE_EXISTING_BIN_COMPILE_AOT=1
  fi
fi
if [[ "${BOOTSTRAP_LOOP_USE_EXISTING_BIN_COMPILE_AOT:-0}" == "1" ]]; then
  if [[ -x "${INVENTORY_DRIVER}" ]] \
    && bootstrap_inventory_argv_driver_smoke "${INVENTORY_DRIVER}" 2>/dev/null; then
    cp -f "${INVENTORY_DRIVER}" "${DRIVER}"
    chmod +x "${DRIVER}"
  elif [[ -x "${PRELINKED_GEN0}" && ! -x "${DRIVER}" ]]; then
    cp -f "${PRELINKED_GEN0}" "${DRIVER}"
    chmod +x "${DRIVER}"
  fi
fi

rm -f "${GEN3}"
set +e
out="$(bootstrap_compile_invoke "${GEN3}" "${SOURCE}" env PHP_COMPILER_SELFHOST_AOT=1 2>&1)"
code=$?
set -e
printf '%s\n' "${out}"
DRIVER="${BOOTSTRAP_COMPILE_DRIVER:-${DRIVER}}"
print_segfault_context "${code}" "${DRIVER}" "${GEN3}" "${SOURCE}"
if [[ "${code}" -ne 0 ]]; then
  echo "bootstrap-loop-gen2-recompile-spine: gen-2 native spine compile failed (exit ${code})" >&2
  exit 1
fi
if [[ ! -x "${GEN3}" ]]; then
  echo "bootstrap-loop-gen2-recompile-spine: missing ${GEN3}" >&2
  exit 1
fi

run_out="$("${GEN3}" 2>&1)"
if ! grep -q 'compiler_lib_spine_smoke bundle OK' <<< "${run_out}"; then
  echo "bootstrap-loop-gen2-recompile-spine: unexpected gen-3 stdout" >&2
  printf '%s\n' "${run_out}" >&2
  exit 1
fi

spine_counts="unknown"
if command -v php >/dev/null 2>&1; then
  # Avoid hardcoding the spine ratio; keep it consistent with the M2 ratio SSOT.
  # Output format: "bootstrap-spine-count: N/M"
  spine_counts="$(php script/bootstrap-spine-count.php 2>/dev/null | sed -n 's/^bootstrap-spine-count: //p' || true)"
  if [[ -z "${spine_counts}" ]]; then
    spine_counts="unknown"
  fi
fi

echo "bootstrap-loop-gen2-recompile-spine: OK gen-2=${DRIVER} gen-3=${GEN3} (${spine_counts} spine, argv -o)"

