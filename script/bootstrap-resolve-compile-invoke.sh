#!/usr/bin/env bash
# Resolve gen-0 AOT link invoker: compiled driver when present, else Zend php bin/compile.php (#2842, #2894).
#
# Usage (from another bootstrap script after setting ROOT):
#   # shellcheck source=bootstrap-resolve-compile-invoke.sh
#   source "$(dirname "$0")/bootstrap-resolve-compile-invoke.sh"
#   bootstrap_compile_invoke "${OUT}" "${ENTRY}"
#   bootstrap_compile_invoke "${OUT}" "${ENTRY}" env PHP_COMPILER_SELFHOST_AOT=1 ...
#
# Opt-out / bisect:
#   BOOTSTRAP_GEN0_ZEND_ONLY=1  — always php bin/compile.php (requires php on PATH)
#   BOOTSTRAP_M5_NO_ZEND=1       — refuse Zend fallback (implies BOOTSTRAP_NO_ZEND_FALLBACK=1)
set -euo pipefail

BOOTSTRAP_COMPILE_DRIVER_MODE=""
BOOTSTRAP_COMPILE_DRIVER=""

# True when DRIVER is the inventory bin/compile.php argv driver (not emit-helper gen-2).
# helloworld-compile-bin copies the same bytes to OUT and build/.m3_bin_compile_aot_blob (#2880).
# Inventory argv driver must be large enough to be real Compiler {main}, not a link sidecar stub (#3012, #3046).
BOOTSTRAP_INVENTORY_ARGV_DRIVER_MIN_BYTES="${BOOTSTRAP_INVENTORY_ARGV_DRIVER_MIN_BYTES:-350000}"

bootstrap_inventory_argv_driver_size_ok() {
  local driver=$1
  local min_bytes="${2:-${BOOTSTRAP_INVENTORY_ARGV_DRIVER_MIN_BYTES}}"
  local driver_bytes
  driver_bytes="$(wc -c <"${driver}" 2>/dev/null || echo 0)"
  [[ "${driver_bytes}" =~ ^[0-9]+$ ]] && (( driver_bytes >= min_bytes ))
}

bootstrap_native_compile_output_ok() {
  local compile_out=$1
  grep -qE 'helloworld_compile_smoke: compile OK|compile_smoke_m3_emit: compile OK|bootstrap_loop_compile_smoke: gen-2 compile OK' <<< "${compile_out}"
}

bootstrap_is_inventory_bin_compile_argv_driver() {
  local driver=$1
  local root="${ROOT:-}"
  if [[ -z "${root}" || ! -x "${driver}" ]]; then
    return 1
  fi
  if [[ "${driver}" == *"/bin-compile-aot-inventory" ]]; then
    bootstrap_inventory_argv_driver_size_ok "${driver}"
    return $?
  fi
  local marker="${root}/build/.m3_bin_compile_aot_blob"
  if [[ ! -f "${marker}" ]]; then
    return 1
  fi
  local driver_bytes marker_bytes
  driver_bytes="$(wc -c <"${driver}" 2>/dev/null || echo 0)"
  marker_bytes="$(wc -c <"${marker}" 2>/dev/null || echo 0)"
  [[ "${driver_bytes}" -gt 0 && "${driver_bytes}" -eq "${marker_bytes}" ]] \
    && bootstrap_inventory_argv_driver_size_ok "${driver}"
}

# Quick argv smoke: inventory driver must emit a real AOT binary, not exit 0 with a sidecar stub (#3046).
bootstrap_inventory_argv_driver_smoke() {
  local driver=$1
  local root="${ROOT:-}"
  local probe="${root}/examples/000-HelloWorld/example.php"
  local smoke_out="${root}/build/.bootstrap-inventory-argv-driver-smoke-aot"
  if [[ -z "${root}" || ! -x "${driver}" || ! -f "${probe}" ]]; then
    return 1
  fi
  rm -f "${smoke_out}"
  local smoke_log=""
  local smoke_code=0
  set +e
  smoke_log="$(
    env -u PHP_COMPILER_M3_SOURCE -u PHP_COMPILER_M3_OUT \
      PHP_COMPILER_SELFHOST_AOT=1 \
      PHP_COMPILER_M3_COMPILE_DRIVER=1 \
      PHP_COMPILER_M4_BIN_COMPILE_DRIVER=1 \
      PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1 \
      BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER=1 \
      "${driver}" -o "${smoke_out}" "${probe}" 2>&1
  )"
  smoke_code=$?
  set -e
  if [[ "${smoke_code}" -eq 0 && -x "${smoke_out}" ]] && bootstrap_native_compile_output_ok "${smoke_log}"; then
    return 0
  fi
  printf '%s\n' "${smoke_log}" >&2
  return 1
}

# Build or refresh build/bin-compile-aot-inventory for M4 full-revision / spine argv paths (#3012).
bootstrap_ensure_inventory_argv_driver() {
  local root="${ROOT:-}"
  local out="${1:-${root}/build/bin-compile-aot-inventory}"
  if [[ -z "${root}" ]]; then
    echo "bootstrap-ensure-inventory-argv-driver: ROOT unset" >&2
    return 1
  fi
  if bootstrap_is_inventory_bin_compile_argv_driver "${out}" && bootstrap_inventory_argv_driver_smoke "${out}"; then
    return 0
  fi
  if [[ -x "${out}" ]]; then
    echo "bootstrap-ensure-inventory-argv-driver: ${out} failed inventory smoke (rebuilding)" >&2
    rm -f "${out}" "${root}/build/.m3_bin_compile_aot_blob"
  fi
  if [[ ! -f "${root}/bin/compile.php" ]]; then
    echo "bootstrap-ensure-inventory-argv-driver: missing ${root}/bin/compile.php" >&2
    return 1
  fi
  echo "bootstrap-ensure-inventory-argv-driver: building inventory argv driver ${out} (#3012)" >&2
  if ! env PHP_COMPILER_M3_SOURCE="${root}/bin/compile.php" PHP_COMPILER_M3_OUT="${out}" \
    "${root}/script/bootstrap-selfhost-helloworld-compile-bin.sh"; then
    echo "bootstrap-ensure-inventory-argv-driver: helloworld-compile-bin failed" >&2
    return 1
  fi
  if ! bootstrap_is_inventory_bin_compile_argv_driver "${out}"; then
    echo "bootstrap-ensure-inventory-argv-driver: ${out} is not a verified inventory argv driver" >&2
    return 1
  fi
}

bootstrap_list_native_compile_drivers() {
  local root="${ROOT:-}"
  if [[ -z "${root}" ]]; then
    echo "bootstrap-resolve-compile-invoke: ROOT unset" >&2
    return 1
  fi

  # If a fully compiled `bin/compile.php` exists, always prefer it for gen-0 bootstrap work (#2894).
  # Keep older driver names as fallbacks for bisection.
  printf '%s\n' \
    "${root}/build/bin-compile-aot-inventory" \
    "${root}/build/bin-compile-aot" \
    "${root}/prelinked/bootstrap-gen0/bin-compile-aot" \
    "${root}/build/selfhost-compile-driver" \
    "${root}/build/selfhost-native-compile-driver"
}

bootstrap_resolve_compile_driver() {
  local root="${ROOT:-}"
  if [[ -z "${root}" ]]; then
    echo "bootstrap-resolve-compile-invoke: ROOT unset" >&2
    return 1
  fi

  if [[ "${BOOTSTRAP_GEN0_ZEND_ONLY:-0}" == "1" ]]; then
    BOOTSTRAP_COMPILE_DRIVER_MODE=zend
    BOOTSTRAP_COMPILE_DRIVER="${root}/bin/compile.php"
    return 0
  fi

  local candidate
  while IFS= read -r candidate; do
    if [[ -x "${candidate}" ]]; then
      BOOTSTRAP_COMPILE_DRIVER_MODE=native
      BOOTSTRAP_COMPILE_DRIVER="${candidate}"
      return 0
    fi
  done < <(bootstrap_list_native_compile_drivers)

  if command -v php >/dev/null 2>&1; then
    BOOTSTRAP_COMPILE_DRIVER_MODE=zend
    BOOTSTRAP_COMPILE_DRIVER="${root}/bin/compile.php"
    return 0
  fi

  BOOTSTRAP_COMPILE_DRIVER_MODE=""
  BOOTSTRAP_COMPILE_DRIVER=""
  return 1
}

bootstrap_compile_invoke_zend() {
  local out=$1
  local entry=$2
  shift 2

  if ! command -v php >/dev/null 2>&1; then
    echo "bootstrap-compile-invoke: Zend gen-0 requires php on PATH (#2842)" >&2
    return 1
  fi

  echo "bootstrap-compile-invoke: php ${BOOTSTRAP_COMPILE_DRIVER} -o ${out} ${entry} (gen-0 Zend)" >&2
  "$@" php "${BOOTSTRAP_COMPILE_DRIVER}" -o "${out}" "${entry}"
}

# Link OUT from ENTRY. Optional prefix: env VAR=val … (same as `env … php bin/compile.php`).
bootstrap_compile_invoke() {
  local out=$1
  local entry=$2
  shift 2

  # Best-effort crash breadcrumbs for compiled drivers (AOT segfaults) (#2969).
  # These are intentionally written from bash before invoking the native driver,
  # since a hard segfault can happen before PHP-level progress logging runs.
  if [[ -z "${PHP_COMPILER_JIT_PROGRESS_FILE:-}" ]]; then
    export PHP_COMPILER_JIT_PROGRESS_FILE="${ROOT}/build/.last-jit-func"
  fi
  if [[ -z "${PHP_COMPILER_JIT_PHASE_FILE:-}" ]]; then
    export PHP_COMPILER_JIT_PHASE_FILE="${ROOT}/build/.last-jit-phase"
  fi
  if [[ -z "${PHP_COMPILER_JIT_ENTRY_FILE:-}" ]]; then
    export PHP_COMPILER_JIT_ENTRY_FILE="${ROOT}/build/.last-jit-entry"
  fi
  printf '%s' "${entry}" > "${PHP_COMPILER_JIT_ENTRY_FILE}" 2>/dev/null || true

  local no_zend_fallback=0
  if [[ "${BOOTSTRAP_M5_NO_ZEND:-0}" == "1" || "${BOOTSTRAP_NO_ZEND_FALLBACK:-0}" == "1" ]]; then
    no_zend_fallback=1
  fi

  if [[ "${BOOTSTRAP_GEN0_ZEND_ONLY:-0}" == "1" ]]; then
    if ! bootstrap_resolve_compile_driver; then
      echo "bootstrap-compile-invoke: no compiled driver under build/ and php missing on PATH (#2842)" >&2
      return 1
    fi
    bootstrap_compile_invoke_zend "${out}" "${entry}" "$@"
    return $?
  fi

  # If a compiled driver exists, try them in priority order before falling back to gen-0 Zend.
  # This avoids "first native candidate crashes → immediate Zend" which hides usable drivers (#2894).
  local native_candidate
  local last_code=1
  local attempted_native=0
  while IFS= read -r native_candidate; do
    if [[ ! -x "${native_candidate}" ]]; then
      continue
    fi
    attempted_native=1
    BOOTSTRAP_COMPILE_DRIVER="${native_candidate}"
    printf '%s' "compile_invoke:${BOOTSTRAP_COMPILE_DRIVER}" > "${PHP_COMPILER_JIT_PHASE_FILE}" 2>/dev/null || true
    echo "bootstrap-compile-invoke: ${BOOTSTRAP_COMPILE_DRIVER} -o ${out} ${entry} (#2842)" >&2
    rm -f "${out}"
    local invoke_out=""
    set +e
    invoke_out="$("$@" "${BOOTSTRAP_COMPILE_DRIVER}" -o "${out}" "${entry}" 2>&1)"
    last_code=$?
    set -e
    printf '%s\n' "${invoke_out}"
    if [[ "${last_code}" -eq 0 && -x "${out}" ]]; then
      if bootstrap_is_inventory_bin_compile_argv_driver "${BOOTSTRAP_COMPILE_DRIVER}"; then
        if ! bootstrap_native_compile_output_ok "${invoke_out}"; then
          echo "bootstrap-compile-invoke: inventory driver exited 0 but missing compile OK line (#3046)" >&2
          last_code=1
          rm -f "${out}"
        elif [[ "${entry}" == *"/bin/compile.php" ]] && ! bootstrap_inventory_argv_driver_size_ok "${out}"; then
          echo "bootstrap-compile-invoke: compiled bin/compile.php output too small (sidecar stub?)" >&2
          last_code=1
          rm -f "${out}"
        else
          return 0
        fi
      else
        return 0
      fi
    fi
    echo "bootstrap-compile-invoke: compiled driver ${BOOTSTRAP_COMPILE_DRIVER} failed (exit ${last_code})" >&2
    if [[ -n "${PHP_COMPILER_JIT_ENTRY_FILE:-}" && -f "${PHP_COMPILER_JIT_ENTRY_FILE}" ]]; then
      echo "bootstrap-compile-invoke: last entry: $(cat "${PHP_COMPILER_JIT_ENTRY_FILE}" 2>/dev/null || true)" >&2
    fi
    if [[ -n "${PHP_COMPILER_JIT_PHASE_FILE:-}" && -f "${PHP_COMPILER_JIT_PHASE_FILE}" ]]; then
      echo "bootstrap-compile-invoke: last phase: $(cat "${PHP_COMPILER_JIT_PHASE_FILE}" 2>/dev/null || true)" >&2
    fi
    if [[ -n "${PHP_COMPILER_JIT_PROGRESS_FILE:-}" && -f "${PHP_COMPILER_JIT_PROGRESS_FILE}" ]]; then
      echo "bootstrap-compile-invoke: last progress: $(cat "${PHP_COMPILER_JIT_PROGRESS_FILE}" 2>/dev/null || true)" >&2
    fi
  done < <(bootstrap_list_native_compile_drivers)

  if [[ "${attempted_native}" -eq 1 && "${no_zend_fallback}" -eq 1 ]]; then
    echo "bootstrap-compile-invoke: compiled driver(s) failed; BOOTSTRAP_NO_ZEND_FALLBACK=1 — no Zend fallback" >&2
    return "${last_code}"
  fi

  if [[ "${BOOTSTRAP_GEN0_ZEND_ONLY:-0}" == "1" ]]; then
    echo "bootstrap-compile-invoke: compiled driver(s) failed; BOOTSTRAP_GEN0_ZEND_ONLY=1 — no fallback" >&2
    return "${last_code}"
  fi
  if [[ "${BOOTSTRAP_NO_ZEND_FALLBACK:-0}" == "1" ]]; then
    echo "bootstrap-compile-invoke: compiled driver(s) failed; BOOTSTRAP_NO_ZEND_FALLBACK=1 — no Zend fallback" >&2
    return "${last_code}"
  fi

  if [[ "${BOOTSTRAP_M5_NO_ZEND:-0}" == "1" ]]; then
    echo "bootstrap-compile-invoke: compiled driver(s) failed; BOOTSTRAP_M5_NO_ZEND=1 — no Zend fallback (#3053)" >&2
    return "${last_code}"
  fi

  if ! command -v php >/dev/null 2>&1; then
    echo "bootstrap-compile-invoke: compiled driver(s) failed and php missing — cannot fall back (#2842)" >&2
    return "${last_code}"
  fi

  echo "bootstrap-compile-invoke: compiled driver(s) failed — falling back to Zend gen-0 (#2842)" >&2
  BOOTSTRAP_COMPILE_DRIVER_MODE=zend
  BOOTSTRAP_COMPILE_DRIVER="${ROOT}/bin/compile.php"
  bootstrap_compile_invoke_zend "${out}" "${entry}" "$@"
}
