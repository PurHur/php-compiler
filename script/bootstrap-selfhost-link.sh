#!/usr/bin/env bash
# Bundled Compiler.php AOT native link + run gate (issues #212, #78, #557, #579).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENTRY="${ROOT}/test/selfhost/compiler_minimal/main.php"
OUT="${ROOT}/build/selfhost"
# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
# shellcheck source=bootstrap-resolve-compile-invoke.sh
source "$(dirname "$0")/bootstrap-resolve-compile-invoke.sh"
# shellcheck source=bootstrap-gen0-install-prelinked-driver.sh
source "$(dirname "$0")/bootstrap-gen0-install-prelinked-driver.sh"
# shellcheck source=selfhost-preflight.sh
source "$(dirname "$0")/selfhost-preflight.sh"
ci_apply_llvm_memory_env

if [[ "${BOOTSTRAP_M5_NO_ZEND:-0}" == "1" ]]; then
  export BOOTSTRAP_NO_ZEND_FALLBACK=1
  export BOOTSTRAP_GEN0_ENSURE_COMPILED_DRIVER=0
  export BOOTSTRAP_ALLOW_GEN0_ZEND=0
  mkdir -p "${ROOT}/build"
  if ! bootstrap_gen0_install_prelinked_driver; then
    echo "bootstrap-selfhost-link: BOOTSTRAP_M5_NO_ZEND=1 requires prelinked/bootstrap-gen0/bin-compile-aot (#3053)" >&2
    exit 1
  fi
  bootstrap_gen0_seed_prelinked_m3_sidecars 2>/dev/null || true
  bootstrap_ensure_m3_compiler_lib_sidecar 2>/dev/null || true
  bootstrap_gen0_copy_prelinked_inventory_driver \
    "${ROOT}/build/bin-compile-aot-inventory" "" "${ROOT}/build/bin-compile-aot-inventory" \
    2>/dev/null || true
fi

if bootstrap_resolve_compile_driver; then
  if [[ "${BOOTSTRAP_COMPILE_DRIVER_MODE}" == "zend" ]]; then
    if [[ "${BOOTSTRAP_M5_NO_ZEND:-0}" == "1" ]]; then
      echo "bootstrap-selfhost-link: BOOTSTRAP_M5_NO_ZEND=1 forbids Zend gen-0 (#3053)" >&2
      exit 1
    fi
    selfhost_preflight bootstrap-selfhost-link php-only
  fi
else
  if [[ "${BOOTSTRAP_M5_NO_ZEND:-0}" == "1" ]]; then
    echo "bootstrap-selfhost-link: BOOTSTRAP_M5_NO_ZEND=1 and no native gen-0 driver (#3053)" >&2
    exit 1
  fi
  selfhost_preflight bootstrap-selfhost-link php-or-docker
fi

if [[ ! -f "${ENTRY}" ]]; then
  echo "bootstrap-selfhost-link: missing ${ENTRY}" >&2
  exit 1
fi

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-selfhost-link: LLVM 9 not found (skip)" >&2
  exit 2
fi

if [[ "${BOOTSTRAP_COMPILE_DRIVER_MODE}" == "zend" && -d "${ROOT}/vendor" ]]; then
  patch_log="$(mktemp)"
  if ! "${ROOT}/script/apply-patches.sh" >"${patch_log}" 2>&1; then
    echo "bootstrap-selfhost-link: apply-patches failed (#2806)" >&2
    cat "${patch_log}" >&2
    rm -f "${patch_log}"
    exit 1
  fi
  rm -f "${patch_log}"
fi

mkdir -p "${ROOT}/build"

if ! bootstrap_gen0_install_prelinked_driver; then
  if [[ "${BOOTSTRAP_M5_NO_ZEND:-0}" == "1" ]]; then
    echo "bootstrap-selfhost-link: BOOTSTRAP_M5_NO_ZEND=1 requires prelinked/bootstrap-gen0/bin-compile-aot (#3053)" >&2
    exit 1
  fi
fi

if [[ "${BOOTSTRAP_GEN0_ENSURE_COMPILED_DRIVER:-1}" == "1" && ! -x "${ROOT}/build/bin-compile-aot" ]]; then
  if [[ "${BOOTSTRAP_M5_NO_ZEND:-0}" == "1" ]]; then
    echo "bootstrap-selfhost-link: BOOTSTRAP_M5_NO_ZEND=1 — refusing Zend driver-smoke (#3053)" >&2
    exit 1
  fi
  echo "bootstrap-selfhost-link: building gen-0 compiled driver (BOOTSTRAP_GEN0_ENSURE_COMPILED_DRIVER=1, #2894)" >&2
  # Best-effort only: on fresh trees the compiled driver can fail independently (#3004).
  # Do not block the primary link gate on a missing optional artifact; proceed and surface
  # the real next compiler failure instead (#3005).
  set +e
  BOOTSTRAP_M5_DRIVER_SMOKE=1 "${ROOT}/script/bootstrap-selfhost-driver-smoke.sh" >/dev/null
  driver_smoke_code=$?
  set -e
  if [[ "${driver_smoke_code}" -ne 0 ]]; then
    echo "bootstrap-selfhost-link: WARNING: bootstrap-selfhost-driver-smoke failed (exit ${driver_smoke_code}); continuing without compiled driver (#3005)" >&2
  fi
fi

export PHP_COMPILER_SELFHOST_AOT=1
export PHP_COMPILER_JIT_PROGRESS_FILE="${ROOT}/build/.last-jit-func"
rm -f "${OUT}" "${PHP_COMPILER_JIT_PROGRESS_FILE}"
if ! bootstrap_compile_invoke "${OUT}" "${ENTRY}" env \
  PHP_COMPILER_SELFHOST_AOT=1 \
  PHP_COMPILER_M3_COMPILE_DRIVER_MAIN=1 2>&1; then
  echo "bootstrap-selfhost-link: compile failed (progress gate; see stderr above)" >&2
  exit 1
fi
test -x "${OUT}"
out="$("${OUT}")"
test "compiler_minimal bundle OK" = "${out//$'\n'/}"
echo "bootstrap-selfhost-link: OK ${OUT}"
