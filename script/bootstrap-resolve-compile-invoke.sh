#!/usr/bin/env bash
# Resolve gen-0 AOT link invoker: compiled driver when present, else Zend php bin/compile.php (#2842).
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

  # Prefer full bin/compile.php host link before argv-only M3 driver (#2842).
  local candidate
  for candidate in \
    "${root}/build/selfhost-compile-driver" \
    "${root}/build/selfhost-native-compile-driver" \
    "${root}/build/bin-compile-aot"; do
    if [[ -x "${candidate}" ]]; then
      BOOTSTRAP_COMPILE_DRIVER_MODE=native
      BOOTSTRAP_COMPILE_DRIVER="${candidate}"
      return 0
    fi
  done

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

  if ! bootstrap_resolve_compile_driver; then
    echo "bootstrap-compile-invoke: no compiled driver under build/ and php missing on PATH (#2842)" >&2
    echo "bootstrap-compile-invoke: one-time gen-0: ./script/docker-exec.sh -- bash -lc 'make bootstrap-selfhost-driver-smoke'" >&2
    echo "bootstrap-compile-invoke: or set BOOTSTRAP_GEN0_ZEND_ONLY=1 with host php + vendor/" >&2
    return 1
  fi

  if [[ "${BOOTSTRAP_COMPILE_DRIVER_MODE}" == "zend" ]]; then
    bootstrap_compile_invoke_zend "${out}" "${entry}" "$@"
    return $?
  fi

  echo "bootstrap-compile-invoke: ${BOOTSTRAP_COMPILE_DRIVER} -o ${out} ${entry} (#2842)" >&2
  set +e
  "$@" "${BOOTSTRAP_COMPILE_DRIVER}" -o "${out}" "${entry}"
  local code=$?
  set -e

  if [[ "${code}" -eq 0 && -x "${out}" ]]; then
    return 0
  fi

  if [[ "${BOOTSTRAP_GEN0_ZEND_ONLY:-0}" == "1" ]]; then
    echo "bootstrap-compile-invoke: compiled driver failed (exit ${code}); BOOTSTRAP_GEN0_ZEND_ONLY=1 — no fallback" >&2
    return "${code}"
  fi

  if ! command -v php >/dev/null 2>&1; then
    echo "bootstrap-compile-invoke: compiled driver failed (exit ${code}) and php missing — cannot fall back (#2842)" >&2
    return "${code}"
  fi

  echo "bootstrap-compile-invoke: compiled driver failed or missing ${out} — falling back to Zend gen-0 (#2842)" >&2
  BOOTSTRAP_COMPILE_DRIVER_MODE=zend
  BOOTSTRAP_COMPILE_DRIVER="${ROOT}/bin/compile.php"
  rm -f "${out}"
  bootstrap_compile_invoke_zend "${out}" "${entry}" "$@"
}
