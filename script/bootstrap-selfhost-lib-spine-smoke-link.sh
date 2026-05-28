#!/usr/bin/env bash
# M2 lib spine smoke: bundled vm.php-path lib/ closure AOT native link + run (issues #1056, #1025).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENTRY="${ROOT}/test/selfhost/compiler_lib_spine_smoke/main.php"
OUT="${ROOT}/build/selfhost-lib-spine-smoke"
# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
# shellcheck source=bootstrap-resolve-compile-invoke.sh
source "$(dirname "$0")/bootstrap-resolve-compile-invoke.sh"
ci_apply_llvm_memory_env

if [[ ! -f "${ENTRY}" ]]; then
  echo "bootstrap-selfhost-lib-spine-smoke-link: missing ${ENTRY}" >&2
  exit 1
fi

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-selfhost-lib-spine-smoke-link: LLVM 9 not found (skip)" >&2
  exit 2
fi

mkdir -p "${ROOT}/build"
export PHP_COMPILER_SELFHOST_AOT=1
export BOOTSTRAP_NO_ZEND_FALLBACK=1
export PHP_COMPILER_JIT_PROGRESS_FILE="${ROOT}/build/.last-jit-func-lib-spine-smoke"
export PHP_COMPILER_JIT_PHASE_FILE="${ROOT}/build/.last-jit-phase-lib-spine-smoke"
export PHP_COMPILER_JIT_ENTRY_FILE="${ROOT}/build/.last-jit-entry-lib-spine-smoke"
rm -f "${OUT}" "${PHP_COMPILER_JIT_PROGRESS_FILE}" "${PHP_COMPILER_JIT_PHASE_FILE}" "${PHP_COMPILER_JIT_ENTRY_FILE}"

# Optional minimization path to bisect segfaults in the compiled inventory argv driver (#2989).
if [[ "${BOOTSTRAP_LIB_SPINE_SMOKE_MINIMIZE:-0}" == "1" ]]; then
  limit="${BOOTSTRAP_LIB_SPINE_SMOKE_REQUIRE_LIMIT:-}"
  if [[ -z "${limit}" ]]; then
    echo "bootstrap-selfhost-lib-spine-smoke-link: BOOTSTRAP_LIB_SPINE_SMOKE_MINIMIZE=1 requires BOOTSTRAP_LIB_SPINE_SMOKE_REQUIRE_LIMIT=<n>" >&2
    exit 1
  fi
  min_entry="${ROOT}/build/spine-minimize-lib-spine-smoke-limit-${limit}.php"
  # Use host PHP to generate a literal-require subset entry; the compiled driver will compile this file.
  php "${ROOT}/script/bootstrap-spine-minimize-entry.php" --in "${ENTRY}" --out "${min_entry}" --limit "${limit}" >&2
  ENTRY="${min_entry}"
fi

INVENTORY_ARGV_DRIVER="${ROOT}/build/bin-compile-aot-inventory"

# Prefer the proven inventory argv driver path (same strategy as bootstrap-selfhost-full-revision-probe, #2968).
if [[ "${BOOTSTRAP_LIB_SPINE_SMOKE_USE_COMPILE_INVOKE:-0}" != "1" ]]; then
  # Reuse a driver already built in this bootstrap session (e.g. after make bootstrap-selfhost-link).
  # Re-linking bin/compile.php via inventory emit often returns null while the host-compile argv
  # driver is already green (#2967, #3004).
  if [[ ! -x "${INVENTORY_ARGV_DRIVER}" && -x "${ROOT}/build/bin-compile-aot" ]]; then
    echo "bootstrap-selfhost-lib-spine-smoke-link: reusing build/bin-compile-aot as inventory argv driver (#2968)" >&2
    cp -f "${ROOT}/build/bin-compile-aot" "${INVENTORY_ARGV_DRIVER}"
    chmod +x "${INVENTORY_ARGV_DRIVER}"
  fi
  if [[ ! -x "${INVENTORY_ARGV_DRIVER}" ]]; then
    echo "bootstrap-selfhost-lib-spine-smoke-link: building inventory argv driver (bin/compile.php) to reduce compiled-driver divergence (#2968)" >&2
    if ! env PHP_COMPILER_M3_SOURCE="${ROOT}/bin/compile.php" PHP_COMPILER_M3_OUT="${INVENTORY_ARGV_DRIVER}" \
      ./script/bootstrap-selfhost-helloworld-compile-bin.sh >/dev/null; then
      echo "bootstrap-selfhost-lib-spine-smoke-link: failed to build inventory argv driver ${INVENTORY_ARGV_DRIVER}" >&2
      exit 1
    fi
  fi
  if [[ ! -x "${INVENTORY_ARGV_DRIVER}" ]]; then
    echo "bootstrap-selfhost-lib-spine-smoke-link: missing inventory argv driver ${INVENTORY_ARGV_DRIVER}" >&2
    exit 1
  fi

  # Best-effort segfault breadcrumbs (written before invoking the native driver).
  printf '%s' "${ENTRY}" > "${PHP_COMPILER_JIT_ENTRY_FILE}" 2>/dev/null || true
  printf '%s' "compile_invoke:${INVENTORY_ARGV_DRIVER}" > "${PHP_COMPILER_JIT_PHASE_FILE}" 2>/dev/null || true
  rm -f "${OUT}"
  set +e
  env -u PHP_COMPILER_M3_SOURCE -u PHP_COMPILER_M3_OUT \
    PHP_COMPILER_SELFHOST_AOT=1 \
    BOOTSTRAP_NO_ZEND_FALLBACK=1 \
    "${INVENTORY_ARGV_DRIVER}" -o "${OUT}" "${ENTRY}" 2>&1
  driver_code=$?
  set -e
  if [[ "${driver_code}" -ne 0 || ! -x "${OUT}" ]]; then
    if [[ -f "${PHP_COMPILER_JIT_PROGRESS_FILE}" ]]; then
      echo "bootstrap-selfhost-lib-spine-smoke-link: last JIT func: $(cat "${PHP_COMPILER_JIT_PROGRESS_FILE}" 2>/dev/null || true)" >&2
    fi
    if [[ -f "${PHP_COMPILER_JIT_PHASE_FILE}" ]]; then
      echo "bootstrap-selfhost-lib-spine-smoke-link: last phase: $(cat "${PHP_COMPILER_JIT_PHASE_FILE}" 2>/dev/null || true)" >&2
    fi
    if [[ -f "${PHP_COMPILER_JIT_ENTRY_FILE}" ]]; then
      echo "bootstrap-selfhost-lib-spine-smoke-link: last entry: $(cat "${PHP_COMPILER_JIT_ENTRY_FILE}" 2>/dev/null || true)" >&2
    fi
    echo "bootstrap-selfhost-lib-spine-smoke-link: inventory argv driver failed; retrying via bootstrap_compile_invoke resolver (no Zend)" >&2
    # Bisect / compatibility: use shared driver resolver (may select non-inventory drivers).
    # north-star5 step 4b requires "no Zend in the compile step".
    if ! bootstrap_resolve_compile_driver || [[ "${BOOTSTRAP_COMPILE_DRIVER_MODE:-}" != "native" ]]; then
      echo "bootstrap-selfhost-lib-spine-smoke-link: building compiled argv driver (bootstrap-selfhost-driver-smoke) to avoid Zend fallback" >&2
      if ! ./script/bootstrap-selfhost-driver-smoke.sh >/dev/null; then
        echo "bootstrap-selfhost-lib-spine-smoke-link: failed to build compiled driver (see stderr above)" >&2
        exit 1
      fi
      if ! bootstrap_resolve_compile_driver || [[ "${BOOTSTRAP_COMPILE_DRIVER_MODE:-}" != "native" ]]; then
        echo "bootstrap-selfhost-lib-spine-smoke-link: compiled driver still missing after driver-smoke (would require Zend fallback)" >&2
        exit 1
      fi
    fi
    if ! bootstrap_compile_invoke "${OUT}" "${ENTRY}" env PHP_COMPILER_SELFHOST_AOT=1 2>&1; then
      if [[ "${BOOTSTRAP_LIB_SPINE_SMOKE_GEN0_FALLBACK:-1}" == "1" ]] && command -v php >/dev/null 2>&1; then
        echo "bootstrap-selfhost-lib-spine-smoke-link: gen-0 Zend fallback (native argv driver blocked; #2967)" >&2
        rm -f "${OUT}"
        php "${ROOT}/bin/compile.php" -o "${OUT}" "${ENTRY}" 2>&1 || true
      fi
      if [[ ! -x "${OUT}" ]]; then
        echo "bootstrap-selfhost-lib-spine-smoke-link: compile failed (progress gate; see stderr above)" >&2
        exit 1
      fi
    fi
  fi
fi

if [[ "${BOOTSTRAP_LIB_SPINE_SMOKE_USE_COMPILE_INVOKE:-0}" == "1" ]]; then
  # Bisect / compatibility: use shared driver resolver (may select non-inventory drivers).
  # north-star5 step 4b requires "no Zend in the compile step".
  if ! bootstrap_resolve_compile_driver || [[ "${BOOTSTRAP_COMPILE_DRIVER_MODE:-}" != "native" ]]; then
    echo "bootstrap-selfhost-lib-spine-smoke-link: building compiled argv driver (bootstrap-selfhost-driver-smoke) to avoid Zend fallback" >&2
    if ! ./script/bootstrap-selfhost-driver-smoke.sh >/dev/null; then
      echo "bootstrap-selfhost-lib-spine-smoke-link: failed to build compiled driver (see stderr above)" >&2
      exit 1
    fi
    if ! bootstrap_resolve_compile_driver || [[ "${BOOTSTRAP_COMPILE_DRIVER_MODE:-}" != "native" ]]; then
      echo "bootstrap-selfhost-lib-spine-smoke-link: compiled driver still missing after driver-smoke (would require Zend fallback)" >&2
      exit 1
    fi
  fi
  if ! bootstrap_compile_invoke "${OUT}" "${ENTRY}" env PHP_COMPILER_SELFHOST_AOT=1 2>&1; then
    echo "bootstrap-selfhost-lib-spine-smoke-link: compile failed (progress gate; see stderr above)" >&2
    exit 1
  fi
fi
test -x "${OUT}"
out="$({ PHP_COMPILER_CLI_SPINE_BUNDLE=1 "${OUT}"; })"
if ! grep -q 'compiler_lib_spine_smoke bundle OK' <<< "${out}"; then
  echo "bootstrap-selfhost-lib-spine-smoke-link: unexpected stdout (want compiler_lib_spine_smoke bundle OK)" >&2
  printf '%s\n' "${out}" >&2
  exit 1
fi
echo "bootstrap-selfhost-lib-spine-smoke-link: OK ${OUT}"
