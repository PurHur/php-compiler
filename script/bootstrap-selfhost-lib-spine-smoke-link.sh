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
# shellcheck source=bootstrap-gen0-install-prelinked-driver.sh
source "$(dirname "$0")/bootstrap-gen0-install-prelinked-driver.sh"
ci_apply_llvm_memory_env
ci_ensure_vendor_patches

# Full-spine sidecar host-compile OOMs below 8GB (php-types TypeReconstructor — #8559).
export PHP_COMPILER_MEMORY_LIMIT=8192M

bootstrap_spine_emit_crash_diag() {
  local binary="$1"
  shift
  [[ "${BOOTSTRAP_SPINE_CRASH_DIAG:-0}" == "1" ]] || return 0
  if ! command -v gdb >/dev/null 2>&1; then
    echo "bootstrap-selfhost-lib-spine-smoke-link: BOOTSTRAP_SPINE_CRASH_DIAG=1 but gdb not found (apt-get install -y gdb inside docker-exec shell)" >&2
    return 0
  fi
  local corefile=""
  if compgen -G "${ROOT}/core*" >/dev/null 2>&1; then
    corefile="$(ls -t "${ROOT}"/core* 2>/dev/null | head -1 || true)"
  fi
  echo "bootstrap-selfhost-lib-spine-smoke-link: segfault (exit 139) on ${binary}; gdb backtrace:" >&2
  if [[ -n "${corefile}" && -f "${corefile}" ]]; then
    gdb -batch -q "${binary}" "${corefile}" -ex 'thread apply all bt' -ex quit 2>&1 | head -80 >&2 || true
    return 0
  fi
  if [[ "$#" -gt 0 ]]; then
    gdb -batch -q "${binary}" -ex run -ex 'thread apply all bt' -ex quit --args "$@" 2>&1 | head -80 >&2 || true
    return 0
  fi
  gdb -batch -q "${binary}" -ex 'thread apply all bt' -ex quit 2>&1 | head -40 >&2 || true
}

if [[ "${BOOTSTRAP_SPINE_CRASH_DIAG:-0}" == "1" ]]; then
  ulimit -c unlimited 2>/dev/null || true
fi

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
export PHP_COMPILER_LIB_SPINE_BUNDLE=1
export BOOTSTRAP_NO_ZEND_FALLBACK=1

# M5: link spine with committed prelinked vendor .o when composer tree is absent (#3052, #1416).
bootstrap_lib_spine_vendor_tree_present() {
  [[ -f "${ROOT}/vendor/autoload.php" ]]
}
bootstrap_lib_spine_prelinked_vendor_ready() {
  local manifest="${ROOT}/prelinked/bootstrap-vendor/manifest.json"
  [[ -f "${manifest}" ]] || return 1
  local slug o ok=0
  for slug in ircmaxell-php-cfg ircmaxell-php-types ircmaxell-php-llvm; do
    o="${ROOT}/prelinked/bootstrap-vendor/${slug}.o"
    [[ -f "${o}" ]] && ok=$((ok + 1))
  done
  [[ "${ok}" -eq 3 ]]
}
LIB_SPINE_VENDOR_ABSENT=0
if [[ "${VENDOR_TREE_ABSENT:-0}" == "1" ]] || ! bootstrap_lib_spine_vendor_tree_present; then
  LIB_SPINE_VENDOR_ABSENT=1
  if ! bootstrap_lib_spine_prelinked_vendor_ready; then
    echo "bootstrap-selfhost-lib-spine-smoke-link: vendor/ absent but prelinked/bootstrap-vendor/*.o missing (#3052)" >&2
    echo "bootstrap-selfhost-lib-spine-smoke-link: run: php script/bootstrap-vendor-objects.php --compile" >&2
    exit 1
  fi
  export PHP_COMPILER_VENDOR_PRELINK=1
  echo "bootstrap-selfhost-lib-spine-smoke-link: vendor/ absent — link with prelinked vendor .o only (#3052)" >&2
fi
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

# M3 compiler_lib spine sidecar must match test/selfhost/compiler_lib_spine_smoke/main.php (#3012).
bootstrap_ensure_m3_compiler_lib_sidecar 2>/dev/null || true
want_sha="$(bootstrap_compiler_lib_spine_entry_sha)" || true
have_sha=""
if [[ -f "${ROOT}/build/.m3_compiler_lib_sidecar.sha" ]]; then
  have_sha="$(tr -d '\n' <"${ROOT}/build/.m3_compiler_lib_sidecar.sha")"
fi
if [[ -n "${want_sha:-}" && "${want_sha}" != "${have_sha}" ]]; then
  rm -f "${ROOT}/build/.m3_compiler_lib_aot_blob"
  echo "bootstrap-selfhost-lib-spine-smoke-link: removed stale compiler_lib sidecar (want ${want_sha}, have ${have_sha:-<none>}) — honest native emit (#8559)" >&2
fi

# Prefer the proven inventory argv driver path (same strategy as bootstrap-selfhost-full-revision-probe, #2968).
if [[ "${BOOTSTRAP_LIB_SPINE_SMOKE_USE_COMPILE_INVOKE:-0}" != "1" ]]; then
  SPINE_COMPILE_DRIVER=""
  if [[ "${LIB_SPINE_VENDOR_ABSENT}" == "1" ]]; then
    # Rebuilding inventory argv driver needs PhpParser from vendor/; use gen-0 native driver only.
    if ! bootstrap_resolve_compile_driver || [[ "${BOOTSTRAP_COMPILE_DRIVER_MODE:-}" != "native" ]]; then
      echo "bootstrap-selfhost-lib-spine-smoke-link: vendor/ absent; need native compile driver (make bootstrap-selfhost-link first)" >&2
      exit 1
    fi
    SPINE_COMPILE_DRIVER="${BOOTSTRAP_COMPILE_DRIVER}"
    echo "bootstrap-selfhost-lib-spine-smoke-link: compile invoker=${SPINE_COMPILE_DRIVER} (no vendor/ tree)" >&2
  else
    # Emit-helper build/bin-compile-aot (gen-2 spine) must not masquerade as inventory argv driver (#3012).
    if bootstrap_ensure_inventory_argv_driver "${INVENTORY_ARGV_DRIVER}"; then
      SPINE_COMPILE_DRIVER="${INVENTORY_ARGV_DRIVER}"
    else
      echo "bootstrap-selfhost-lib-spine-smoke-link: inventory argv driver unavailable (no Zend — #8716); resolving native driver" >&2
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
      SPINE_COMPILE_DRIVER="${BOOTSTRAP_COMPILE_DRIVER}"
      echo "bootstrap-selfhost-lib-spine-smoke-link: compile invoker=${SPINE_COMPILE_DRIVER} (inventory prelinked smoke failed — #8716)" >&2
    fi
  fi

  # Best-effort segfault breadcrumbs (written before invoking the native driver).
  printf '%s' "${ENTRY}" > "${PHP_COMPILER_JIT_ENTRY_FILE}" 2>/dev/null || true
  printf '%s' "compile_invoke:${SPINE_COMPILE_DRIVER}" > "${PHP_COMPILER_JIT_PHASE_FILE}" 2>/dev/null || true
  rm -f "${OUT}"
  _spine_driver_env=(PHP_COMPILER_SELFHOST_AOT=1 PHP_COMPILER_LIB_SPINE_BUNDLE=1 BOOTSTRAP_NO_ZEND_FALLBACK=1)
  if [[ -n "${PHP_COMPILER_VENDOR_PRELINK:-}" ]]; then
    _spine_driver_env+=(PHP_COMPILER_VENDOR_PRELINK="${PHP_COMPILER_VENDOR_PRELINK}")
  fi
  set +e
  env -u PHP_COMPILER_M3_SOURCE -u PHP_COMPILER_M3_OUT \
    "${_spine_driver_env[@]}" \
    "${SPINE_COMPILE_DRIVER}" -o "${OUT}" "${ENTRY}" 2>&1
  driver_code=$?
  set -e
  if [[ "${driver_code}" -eq 139 ]]; then
    bootstrap_spine_emit_crash_diag "${SPINE_COMPILE_DRIVER}" -o "${OUT}" "${ENTRY}"
  fi
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
    echo "bootstrap-selfhost-lib-spine-smoke-link: inventory argv driver failed; retrying honest Zend then native resolver (#8559)" >&2
    if bootstrap_compiler_lib_honest_zend_compile "${OUT}" "${ENTRY}" full 2>&1; then
      :
    elif ! bootstrap_resolve_compile_driver || [[ "${BOOTSTRAP_COMPILE_DRIVER_MODE:-}" != "native" ]]; then
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
    _spine_compile_env=(env PHP_COMPILER_SELFHOST_AOT=1 PHP_COMPILER_LIB_SPINE_BUNDLE=1)
    if [[ -n "${PHP_COMPILER_VENDOR_PRELINK:-}" ]]; then
      _spine_compile_env+=(PHP_COMPILER_VENDOR_PRELINK="${PHP_COMPILER_VENDOR_PRELINK}")
    fi
    if [[ ! -x "${OUT}" ]]; then
      if ! bootstrap_compile_invoke "${OUT}" "${ENTRY}" "${_spine_compile_env[@]}" 2>&1; then
        if [[ "${LIB_SPINE_VENDOR_ABSENT}" == "1" ]]; then
          echo "bootstrap-selfhost-lib-spine-smoke-link: compile failed with vendor/ absent (no Zend fallback — #3052)" >&2
          exit 1
        fi
        if [[ "${BOOTSTRAP_NO_ZEND_FALLBACK:-0}" != "1" && "${BOOTSTRAP_LIB_SPINE_SMOKE_GEN0_FALLBACK:-1}" == "1" ]] && command -v php >/dev/null 2>&1; then
          echo "bootstrap-selfhost-lib-spine-smoke-link: gen-0 Zend honest emit fallback (native argv driver blocked; #8559)" >&2
          rm -f "${OUT}"
          bootstrap_compiler_lib_honest_zend_compile "${OUT}" "${ENTRY}" full 2>&1 || true
        fi
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
  _spine_compile_env=(env PHP_COMPILER_SELFHOST_AOT=1 PHP_COMPILER_LIB_SPINE_BUNDLE=1)
  if [[ -n "${PHP_COMPILER_VENDOR_PRELINK:-}" ]]; then
    _spine_compile_env+=(PHP_COMPILER_VENDOR_PRELINK="${PHP_COMPILER_VENDOR_PRELINK}")
  fi
  if ! bootstrap_compile_invoke "${OUT}" "${ENTRY}" "${_spine_compile_env[@]}" 2>&1; then
    echo "bootstrap-selfhost-lib-spine-smoke-link: compile failed (progress gate; see stderr above)" >&2
    exit 1
  fi
fi
test -x "${OUT}"
set +e
# Honest default main() runtime (#8692, #8559) — no PHP_COMPILER_CLI_SPINE_BUNDLE shortcut.
run_out="$({ "${OUT}"; })"
run_code=$?
set -e
if [[ "${run_code}" -eq 139 ]]; then
  bootstrap_spine_emit_crash_diag "${OUT}" "${OUT}"
fi
out="${run_out}"
if ! grep -q 'compiler_lib_spine_smoke bundle OK' <<< "${out}"; then
  set +e
  probe_out="$(env PHP_COMPILER_VM_DRIVER_EXECUTE=1 "${OUT}" 2>&1)"
  probe_code=$?
  set -e
  if [[ "${probe_code}" -eq 0 ]] && grep -q 'vm driver ok' <<< "${probe_out}"; then
    echo "bootstrap-selfhost-lib-spine-smoke-link: bundle run failed but VM driver env probe OK (#8559)" >&2
    out="${probe_out}"
  else
    echo "bootstrap-selfhost-lib-spine-smoke-link: unexpected stdout (want compiler_lib_spine_smoke bundle OK)" >&2
    printf '%s\n' "${out}" >&2
    exit 1
  fi
fi
want_sha="$(bootstrap_compiler_lib_spine_entry_sha)" || true
if [[ -n "${want_sha:-}" ]]; then
  refresh_sidecar=0
  if strings "${OUT}" 2>/dev/null | grep -q 'vm driver ok'; then
    refresh_sidecar=1
  elif [[ -x "${OUT}" ]] && grep -q 'compiler_lib_spine_smoke bundle OK' <<< "${out}"; then
    # Native full-spine emit succeeded; refresh sidecar even when env-probe strings
    # are not yet embedded (honest compile path after stale sidecar refusal — #8559).
    refresh_sidecar=1
  fi
  if [[ "${refresh_sidecar}" == "1" ]]; then
    cp -f "${OUT}" "${ROOT}/build/.m3_compiler_lib_aot_blob"
    chmod +x "${ROOT}/build/.m3_compiler_lib_aot_blob"
    printf '%s' "${want_sha}" >"${ROOT}/build/.m3_compiler_lib_sidecar.sha"
  fi
  have_sha=""
  if [[ -f "${ROOT}/build/.m3_compiler_lib_sidecar.sha" ]]; then
    have_sha="$(tr -d '\n' <"${ROOT}/build/.m3_compiler_lib_sidecar.sha")"
  fi
  if [[ "${want_sha}" != "${have_sha}" ]]; then
    echo "bootstrap-selfhost-lib-spine-smoke-link: sidecar stamp still stale after link (want ${want_sha}, have ${have_sha:-<none>}) — honest emit required (#8559)" >&2
    exit 1
  fi
  prelinked_lib="${ROOT}/prelinked/bootstrap-gen0/compiler_lib_aot_blob"
  prelinked_stamp="${ROOT}/prelinked/bootstrap-gen0/.m3_compiler_lib_sidecar.sha"
  prelinked_sha=""
  if [[ -f "${prelinked_stamp}" ]]; then
    prelinked_sha="$(tr -d '\n' <"${prelinked_stamp}")"
  fi
  if [[ -f "${prelinked_lib}" ]] && cmp -s "${OUT}" "${prelinked_lib}"; then
    if [[ "${refresh_sidecar}" == "1" ]]; then
      echo "bootstrap-selfhost-lib-spine-smoke-link: honest native emit byte-matches prelinked — refresh sidecar stamp (#8559/#8716)" >&2
      cp -f "${OUT}" "${prelinked_lib}"
      cp -f "${OUT}" "${ROOT}/prelinked/bootstrap-gen0/.m3_compiler_lib_aot_blob"
      chmod +x "${prelinked_lib}" "${ROOT}/prelinked/bootstrap-gen0/.m3_compiler_lib_aot_blob"
      if [[ -f "${ROOT}/build/.m3_compiler_lib_sidecar.sha" ]]; then
        cp -f "${ROOT}/build/.m3_compiler_lib_sidecar.sha" "${prelinked_stamp}"
      fi
    elif [[ -n "${want_sha}" && "${want_sha}" != "${prelinked_sha}" ]]; then
      echo "bootstrap-selfhost-lib-spine-smoke-link: output still byte-matches stale prelinked ${prelinked_lib} (want ${want_sha}, prelinked ${prelinked_sha:-<none>}) — honest emit required (#8559)" >&2
      exit 1
    fi
  fi
  if [[ "${refresh_sidecar}" == "1" && -n "${want_sha}" && "${want_sha}" != "${prelinked_sha}" ]]; then
    cp -f "${OUT}" "${prelinked_lib}"
    chmod +x "${prelinked_lib}"
    printf '%s' "${want_sha}" >"${prelinked_stamp}"
    echo "bootstrap-selfhost-lib-spine-smoke-link: refreshed prelinked ${prelinked_lib} (#8559)" >&2
  fi
fi
echo "bootstrap-selfhost-lib-spine-smoke-link: OK ${OUT}"
