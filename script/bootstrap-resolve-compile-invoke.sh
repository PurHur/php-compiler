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
set -euo pipefail

BOOTSTRAP_COMPILE_DRIVER_MODE=""
BOOTSTRAP_COMPILE_DRIVER=""

# True when DRIVER is the inventory bin/compile.php argv driver (not emit-helper gen-2).
# helloworld-compile-bin copies the same bytes to OUT and build/.m3_bin_compile_aot_blob (#2880).
bootstrap_is_inventory_bin_compile_argv_driver() {
  local driver=$1
  local root="${ROOT:-}"
  if [[ -z "${root}" || ! -x "${driver}" ]]; then
    return 1
  fi
  if [[ "${driver}" == *"/bin-compile-aot-inventory" ]]; then
    return 0
  fi
  local marker="${root}/build/.m3_bin_compile_aot_blob"
  if [[ ! -f "${marker}" ]]; then
    return 1
  fi
  local driver_bytes marker_bytes
  driver_bytes="$(wc -c <"${driver}" 2>/dev/null || echo 0)"
  marker_bytes="$(wc -c <"${marker}" 2>/dev/null || echo 0)"
  [[ "${driver_bytes}" -gt 0 && "${driver_bytes}" -eq "${marker_bytes}" ]]
}

# Build or refresh build/bin-compile-aot-inventory for M4 full-revision / spine argv paths (#3012).
bootstrap_ensure_inventory_argv_driver() {
  local root="${ROOT:-}"
  local out="${1:-${root}/build/bin-compile-aot-inventory}"
  if [[ -z "${root}" ]]; then
    echo "bootstrap-ensure-inventory-argv-driver: ROOT unset" >&2
    return 1
  fi
  if bootstrap_is_inventory_bin_compile_argv_driver "${out}"; then
    return 0
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
  if [[ "${BOOTSTRAP_NO_ZEND_FALLBACK:-0}" == "1" ]]; then
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
    set +e
    "$@" "${BOOTSTRAP_COMPILE_DRIVER}" -o "${out}" "${entry}"
    last_code=$?
    set -e
    if [[ "${last_code}" -eq 0 && -x "${out}" ]]; then
      return 0
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

  if ! command -v php >/dev/null 2>&1; then
    echo "bootstrap-compile-invoke: compiled driver(s) failed and php missing — cannot fall back (#2842)" >&2
    return "${last_code}"
  fi

  echo "bootstrap-compile-invoke: compiled driver(s) failed — falling back to Zend gen-0 (#2842)" >&2
  BOOTSTRAP_COMPILE_DRIVER_MODE=zend
  BOOTSTRAP_COMPILE_DRIVER="${ROOT}/bin/compile.php"
  bootstrap_compile_invoke_zend "${out}" "${entry}" "$@"
}
