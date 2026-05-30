#!/usr/bin/env bash
# M4/M5: native driver recompiles full compiler spine — issue #1498, #2697, #2866.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "${ROOT}"

# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
ci_apply_llvm_memory_env

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

# Always (re)build the emit-helper compile driver (argv `-o OUT SOURCE.php`) explicitly.
# Do not route through bootstrap-selfhost-helloworld-compile-bin.sh here: it may produce
# a different `build/bin-compile-aot` (inventory bin/compile.php) which can segfault on
# spine-scale sources (#2930).
if [[ "${BOOTSTRAP_LOOP_USE_EXISTING_BIN_COMPILE_AOT:-0}" != "1" ]]; then
  EMIT_ENTRY="${ROOT}/test/selfhost/compiler_helloworld_smoke/compile_driver.php"
  rm -f "${DRIVER}"
  _driver_debug_env=()
  if [[ "${BOOTSTRAP_DEBUG:-0}" == "1" || "${-}" == *x* ]]; then
    _driver_debug_env+=(
      PHP_COMPILER_DEBUG_LAST_PHASE=1
      PHP_COMPILER_DEBUG_LAST_PHASE_FILE="${ROOT}/build/last_lowering_phase.json"
      PHP_COMPILER_DEBUG_LAST_PHASE_STDERR=1
    )
    rm -f "${ROOT}/build/last_lowering_phase.json" || true
  fi
  # Inventory argv driver (bin/compile.php {main}) — compile_driver.php AOT only prints "ready" without argv (#3011).
  env "${_driver_debug_env[@]}" -u PHP_COMPILER_EMIT_HELPER_LINK \
    PHP_COMPILER_SELFHOST_AOT=1 PHP_COMPILER_M3_COMPILE_DRIVER=1 PHP_COMPILER_M3_COMPILE_DRIVER_MAIN=1 \
    PHP_COMPILER_M4_BIN_COMPILE_DRIVER=1 PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1 \
    BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER=1 PHP_COMPILER_M3_EMIT_LOG_PREFIX=helloworld_compile_smoke \
    php "${ROOT}/bin/compile.php" -o "${DRIVER}" "${ROOT}/bin/compile.php" >/dev/null
fi
if [[ ! -x "${DRIVER}" ]]; then
  echo "bootstrap-loop-gen2-recompile-spine: missing native driver ${DRIVER}" >&2
  exit 1
fi

rm -f "${GEN3}"
set +e
out="$(
  _debug_env=()
  # Optional crash-triage breadcrumbs: last lowering phase / op / func (#2941).
  # Auto-enable in `bash -x` mode or when BOOTSTRAP_DEBUG=1 is set.
  if [[ "${BOOTSTRAP_DEBUG:-0}" == "1" || "${-}" == *x* ]]; then
    _debug_env+=(
      PHP_COMPILER_DEBUG_LAST_PHASE=1
      PHP_COMPILER_DEBUG_LAST_PHASE_FILE="${ROOT}/build/last_lowering_phase.json"
      PHP_COMPILER_DEBUG_LAST_PHASE_STDERR=1
    )
    rm -f "${ROOT}/build/last_lowering_phase.json" || true
  fi

  env -u PHP_COMPILER_M3_SOURCE -u PHP_COMPILER_M3_OUT "${_debug_env[@]}" \
    "${DRIVER}" -o "${GEN3}" "${SOURCE}" 2>&1
)"
code=$?
set -e
printf '%s\n' "${out}"
print_segfault_context "${code}" "${DRIVER}" "${GEN3}" "${SOURCE}"
if [[ "${code}" -ne 0 ]] || ! grep -qE 'compile_smoke_m3_emit: compile OK|helloworld_compile_smoke: compile OK' <<< "${out}"; then
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

