#!/usr/bin/env bash
# Inventory argv driver emit-helper sidecar probe (issue #15604).
#
# Reports whether bin/compile.php inventory argv link succeeds without M3 emit-helper TU
# or link-time sidecar prep (gen-0 sidecar seed, compiler_lib blob reuse).
#
# Usage:
#   ./script/bootstrap-inventory-argv-probe.sh [--check] [--strict]
#
# Env (probe link only):
#   PHP_COMPILER_M3_INVENTORY_NO_EMIT_HELPER_SIDECAR=1  skip emit-helper sidecar registration (#15604)
#
# Exit codes:
#   0  --check OK, or sidecar-free inventory argv link succeeded
#   1  missing entry/scripts, or --strict with gap still present
#   2  LLVM 9 not found (skip)
#   3  gap present — inventory argv link still needs emit-helper / sidecar (default, informational)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENTRY="${ROOT}/bin/compile.php"
PROBE_OUT="${ROOT}/build/bootstrap-inventory-argv-probe-out"
CHECK_ONLY=0
STRICT=0

usage() {
  cat <<EOF
Usage: script/bootstrap-inventory-argv-probe.sh [--check] [--strict]

Inventory argv driver sidecar probe (#15604). Attempts bin/compile.php inventory argv link
without PHP_COMPILER_EMIT_HELPER_LINK and without bootstrap sidecar prep.

  --check   Static wiring only (fast PHPUnit / CI)
  --strict  Exit 1 when sidecar-free link is still blocked (future gate)

Examples:
  make bootstrap-inventory-argv-probe
  ./script/bootstrap-inventory-argv-probe.sh --check
EOF
}

for arg in "$@"; do
  case "${arg}" in
    --check) CHECK_ONLY=1 ;;
    --strict) STRICT=1 ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "bootstrap-inventory-argv-probe: unknown argument: ${arg}" >&2
      usage >&2
      exit 1
      ;;
  esac
done

# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
# shellcheck source=bootstrap-resolve-compile-invoke.sh
source "$(dirname "$0")/bootstrap-resolve-compile-invoke.sh"
# shellcheck source=bootstrap-honest-compile-lib.sh
source "$(dirname "$0")/bootstrap-honest-compile-lib.sh"

bootstrap_inventory_argv_probe_check() {
  local missing=0
  for path in \
    "${ROOT}/bin/compile.php" \
    "${ROOT}/script/bootstrap-inventory-argv-probe.sh" \
    "${ROOT}/script/bootstrap-honest-compile-lib.sh"; do
    if [[ ! -f "${path}" ]]; then
      echo "bootstrap-inventory-argv-probe: missing ${path}" >&2
      missing=1
    fi
  done
  if [[ "${missing}" -ne 0 ]]; then
    return 1
  fi
  echo "bootstrap-inventory-argv-probe: check OK (emit_helper_link=0 sidecar_prep=0 PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1 #15604)"
  return 0
}

if [[ "${CHECK_ONLY}" -eq 1 ]]; then
  bootstrap_inventory_argv_probe_check
  exit $?
fi

if [[ ! -f "${ENTRY}" ]]; then
  echo "bootstrap-inventory-argv-probe: missing ${ENTRY}" >&2
  exit 1
fi

ci_apply_llvm_memory_env

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-inventory-argv-probe: LLVM 9 not found (skip)" >&2
  exit 2
fi

mkdir -p "${ROOT}/build"
rm -f "${PROBE_OUT}"
export PHP_COMPILER_JIT_PROGRESS_FILE="${ROOT}/build/.last-jit-func-inventory-argv-probe"
rm -f "${PHP_COMPILER_JIT_PROGRESS_FILE}"

echo "bootstrap-inventory-argv-probe: attempting sidecar-free inventory argv link (#15604)" >&2
echo "bootstrap-inventory-argv-probe: emit_helper_link=0 sidecar_prep=0 PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1" >&2

set +e
link_out="$(
  env -u PHP_COMPILER_EMIT_HELPER_LINK \
    -u PHP_COMPILER_M3_EMIT_TU \
    -u PHP_COMPILER_M3_SOURCE \
    -u PHP_COMPILER_M3_OUT \
    PHP_COMPILER_REPO_ROOT="${ROOT}" \
    PHP_COMPILER_SELFHOST_AOT=1 \
    PHP_COMPILER_M3_COMPILE_DRIVER=1 \
    PHP_COMPILER_M3_COMPILE_DRIVER_MAIN=1 \
    PHP_COMPILER_M4_BIN_COMPILE_DRIVER=1 \
    PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1 \
    BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER=1 \
    PHP_COMPILER_M3_INVENTORY_NO_EMIT_HELPER_SIDECAR=1 \
    PHP_COMPILER_M3_INVENTORY_MINIMAL_SIDECARS=0 \
    BOOTSTRAP_INVENTORY_MINIMAL_SIDECARS=0 \
    PHP_COMPILER_M3_REUSE_STALE_COMPILER_LIB_SIDECAR=0 \
    BOOTSTRAP_ALLOW_STALE_SIDECAR=0 \
    PHP_COMPILER_M3_EMIT_LOG_PREFIX=helloworld_compile_smoke \
    php "${ROOT}/bin/compile.php" -o "${PROBE_OUT}" "${ENTRY}" 2>&1
)"
link_code=$?
set -e
printf '%s\n' "${link_out}"

sidecar_free=blocked
if [[ "${link_code}" -eq 0 ]] \
  && bootstrap_native_compile_output_ok "${link_out}" \
  && bootstrap_inventory_argv_emit_output_ok "${PROBE_OUT}" \
  && ! bootstrap_honest_compile_log_uses_sidecar_recovery "${link_out}"; then
  sidecar_free=ok
fi

echo "bootstrap-inventory-argv-probe: sidecar_free=${sidecar_free} exit_link=${link_code} (#15604)"

if [[ "${sidecar_free}" == "ok" ]]; then
  echo "bootstrap-inventory-argv-probe: OK inventory argv driver links without emit-helper sidecar"
  exit 0
fi

echo "bootstrap-inventory-argv-probe: GAP — inventory argv driver still depends on emit-helper / link-time sidecars (#15604, #2866)" >&2
echo "bootstrap-inventory-argv-probe: see docs/bootstrap-dev-workflow.md · track #15604" >&2
if [[ "${STRICT}" -eq 1 ]]; then
  exit 1
fi
exit 3
