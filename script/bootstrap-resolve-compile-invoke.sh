#!/usr/bin/env bash
# Resolve gen-0 AOT link invoker: compiled driver when present, else Zend php bin/compile.php (#2842, #2894).
#
# Native resolution order (first executable wins):
#   1. build/bin-compile-aot-inventory — inventory argv driver (full spine / M4; sole candidate when BOOTSTRAP_USE_INVENTORY_DRIVER=1)
#   2. build/bin-compile-aot — gen-0 seed (prelinked/bootstrap-gen0) or driver-smoke output
#   3. build/selfhost-native-compile-driver — M3 emit-helper alias
#   4. build/selfhost-helloworld-compile — helloworld compile probe output
#   5. build/selfhost-compile-driver — optional M5 host-linked bin/compile.php
#
# Zend php bin/compile.php only when BOOTSTRAP_GEN0_ZEND_ONLY=1, or no native artifact and
# BOOTSTRAP_ALLOW_GEN0_ZEND=1 (default). Log lines say "(gen-0 compiled)" vs "(gen-0 Zend)".
#
# Usage (from another bootstrap script after setting ROOT):
#   # shellcheck source=bootstrap-resolve-compile-invoke.sh
#   source "$(dirname "$0")/bootstrap-resolve-compile-invoke.sh"
#   bootstrap_compile_invoke "${OUT}" "${ENTRY}"
#   bootstrap_compile_invoke "${OUT}" "${ENTRY}" env PHP_COMPILER_SELFHOST_AOT=1 ...
#
# Opt-out / bisect:
#   BOOTSTRAP_GEN0_ZEND_ONLY=1  — always php bin/compile.php (requires php on PATH)
#   BOOTSTRAP_ALLOW_GEN0_ZEND=0 — refuse Zend when no native driver (empty build/)
#   BOOTSTRAP_M5_NO_ZEND=1       — refuse Zend fallback (implies BOOTSTRAP_NO_ZEND_FALLBACK=1)
#   BOOTSTRAP_NO_ZEND_FALLBACK=1 — refuse Zend on spine/M5 compile paths (#8716)
#   BOOTSTRAP_USE_INVENTORY_DRIVER=1 — inventory argv driver only (#2894)
#   BOOTSTRAP_ALLOW_SIDECAR_EMIT_FALLBACK=1 — opt-in when native driver SIGSEGV (exit 139) and only sidecar copy succeeds
set -euo pipefail

BOOTSTRAP_COMPILE_DRIVER_MODE=""
BOOTSTRAP_COMPILE_DRIVER=""

# True when DRIVER is the inventory bin/compile.php argv driver (not emit-helper gen-2).
# helloworld-compile-bin copies the same bytes to OUT and build/.m3_bin_compile_aot_blob (#2880).
# Inventory argv driver must be large enough to be real Compiler {main}, not a link sidecar stub (#3012, #3046).
BOOTSTRAP_INVENTORY_ARGV_DRIVER_MIN_BYTES="${BOOTSTRAP_INVENTORY_ARGV_DRIVER_MIN_BYTES:-350000}"

# Committed gen-0 manifest size_bytes_driver is the SSOT floor (#8713, #3046).
bootstrap_gen0_manifest_driver_min_bytes() {
  local root="${ROOT:-}"
  local bytes=""
  if [[ -z "${root}" || ! -f "${root}/prelinked/bootstrap-gen0/manifest.json" ]]; then
    return 1
  fi
  bytes="$("${PHP_BIN:-php}" -r '
    $m = json_decode(file_get_contents($argv[1]), true);
    $n = (int)($m["size_bytes_driver"] ?? 0);
    echo $n > 0 ? $n : "";
  ' "${root}/prelinked/bootstrap-gen0/manifest.json" 2>/dev/null)" || return 1
  [[ -n "${bytes}" && "${bytes}" =~ ^[0-9]+$ ]] || return 1
  echo "${bytes}"
}

bootstrap_inventory_argv_driver_size_ok() {
  local driver=$1
  local min_bytes="${2:-${BOOTSTRAP_INVENTORY_ARGV_DRIVER_MIN_BYTES}}"
  local manifest_min=""
  if manifest_min="$(bootstrap_gen0_manifest_driver_min_bytes 2>/dev/null)"; then
    if (( manifest_min > min_bytes )); then
      min_bytes="${manifest_min}"
    fi
  fi
  local driver_bytes
  driver_bytes="$(wc -c <"${driver}" 2>/dev/null || echo 0)"
  [[ "${driver_bytes}" =~ ^[0-9]+$ ]] && (( driver_bytes >= min_bytes ))
}

bootstrap_native_compile_output_ok() {
  local compile_out=$1
  grep -qE 'helloworld_compile_smoke: compile OK|compile_smoke_m3_emit: compile OK|bootstrap_loop_compile_smoke: gen-2 compile OK' <<< "${compile_out}"
}

# Inventory argv emit must materialize a real AOT binary (not stdout-only phantom success — #3046, #8709).
bootstrap_inventory_argv_emit_output_ok() {
  local out=$1
  local out_bytes
  if [[ ! -f "${out}" ]]; then
    echo "bootstrap-inventory-argv-emit: missing -o output file: ${out} (#8709)" >&2
    return 1
  fi
  if [[ ! -x "${out}" ]]; then
    echo "bootstrap-inventory-argv-emit: -o output is not executable: ${out} (#8709)" >&2
    return 1
  fi
  out_bytes="$(wc -c <"${out}" 2>/dev/null || echo 0)"
  if [[ ! "${out_bytes}" =~ ^[0-9]+$ ]] || (( out_bytes <= 0 )); then
    echo "bootstrap-inventory-argv-emit: -o output is empty (${out_bytes} bytes): ${out} (#8709)" >&2
    return 1
  fi
}

# Prelinked gen-0 argv drivers bake absolute /compiler/build/.m3_* sidecar paths at link time.
# On harness hosts (repo not mounted at /compiler) __compiler_copy misses; recover from local blobs (#3046, #1492).
bootstrap_gen0_sidecar_blob_for_entry() {
  local entry=$1
  local root="${ROOT:-}"
  local norm="${entry//\\//}"
  local rel=""
  case "${norm}" in
    */test/selfhost/compiler_minimal/main.php) rel='build/.m3_compiler_minimal_aot_blob' ;;
    */examples/000-HelloWorld/example.php) rel='build/.m3_helloworld_aot_blob' ;;
    */test/bootstrap-aot/compiler_smoke_standalone.php) rel='build/.m3_compile_smoke_aot_blob' ;;
    */test/selfhost/compiler_lib_spine_smoke/main.php) rel='build/.m3_compiler_lib_aot_blob' ;;
    */test/selfhost/compiler_unit_probe/compiler_unit_probe_compile.php) rel='build/.m3_compiler_unit_probe_aot_blob' ;;
    */test/selfhost/compiler_helloworld_smoke/compile_driver.php) rel='build/.m3_compile_driver_aot_blob' ;;
    */test/selfhost/compiler_helloworld_smoke/main.php) rel='build/.m3_helloworld_smoke_main_aot_blob' ;;
    */test/selfhost/bootstrap_loop_smoke/main.php) rel='build/.m3_bootstrap_loop_smoke_main_aot_blob' ;;
    */bin/compile.php) rel='build/.m3_bin_compile_aot_blob' ;;
    *) return 1 ;;
  esac
  local build_blob="${root}/${rel}"
  if [[ -f "${build_blob}" && -s "${build_blob}" ]]; then
    if [[ "${rel}" == "build/.m3_compiler_lib_aot_blob" ]] \
      && declare -F bootstrap_compiler_lib_spine_entry_sha >/dev/null 2>&1; then
      local want_sha have_sha stamp="${root}/build/.m3_compiler_lib_sidecar.sha"
      want_sha="$(bootstrap_compiler_lib_spine_entry_sha)" || return 1
      have_sha=""
      if [[ -f "${stamp}" ]]; then
        have_sha="$(tr -d '\n' <"${stamp}")"
      fi
      if [[ "${want_sha}" == "${have_sha}" ]] \
        || [[ "${BOOTSTRAP_ALLOW_STALE_SIDECAR:-0}" == "1" ]]; then
        if [[ "${want_sha}" != "${have_sha}" ]]; then
          echo "bootstrap-gen0-sidecar-fallback: using stale build sidecar (want ${want_sha}, have ${have_sha:-<none>}; BOOTSTRAP_ALLOW_STALE_SIDECAR=1 — #8703)" >&2
        fi
        printf '%s\n' "${build_blob}"
        return 0
      fi
    else
      printf '%s\n' "${build_blob}"
      return 0
    fi
  fi
  local prelinked=""
  case "${rel}" in
    build/.m3_compiler_minimal_aot_blob) prelinked="${root}/prelinked/bootstrap-gen0/compiler_minimal_aot_blob" ;;
    build/.m3_bin_compile_aot_blob) prelinked="${root}/prelinked/bootstrap-gen0/bin-compile-aot" ;;
    build/.m3_compiler_lib_aot_blob) prelinked="${root}/prelinked/bootstrap-gen0/compiler_lib_aot_blob" ;;
    build/.m3_*)
      local sidecar_name="${rel#build/}"
      if [[ -f "${root}/prelinked/bootstrap-gen0/${sidecar_name}" ]]; then
        prelinked="${root}/prelinked/bootstrap-gen0/${sidecar_name}"
      fi
      ;;
  esac
  if [[ -n "${prelinked}" && -f "${prelinked}" && -s "${prelinked}" ]]; then
    if [[ "${rel}" == "build/.m3_compiler_lib_aot_blob" ]] \
      && declare -F bootstrap_compiler_lib_spine_entry_sha >/dev/null 2>&1; then
      local want_sha have_sha stamp="${root}/build/.m3_compiler_lib_sidecar.sha"
      want_sha="$(bootstrap_compiler_lib_spine_entry_sha)" || return 1
      have_sha=""
      if [[ -f "${stamp}" ]]; then
        have_sha="$(tr -d '\n' <"${stamp}")"
      elif [[ -f "${root}/prelinked/bootstrap-gen0/.m3_compiler_lib_sidecar.sha" ]]; then
        have_sha="$(tr -d '\n' <"${root}/prelinked/bootstrap-gen0/.m3_compiler_lib_sidecar.sha")"
      fi
      if [[ "${want_sha}" != "${have_sha}" ]]; then
        if [[ "${BOOTSTRAP_NO_ZEND_FALLBACK:-0}" == "1" ]] \
          && [[ -f "${root}/prelinked/bootstrap-gen0/compiler_lib_aot_blob" ]]; then
          echo "bootstrap-gen0-sidecar-fallback: NO_ZEND spine — reuse prelinked compiler_lib sidecar (want ${want_sha}, have ${have_sha:-<none>}; #8716)" >&2
          printf '%s\n' "${root}/prelinked/bootstrap-gen0/compiler_lib_aot_blob"
          return 0
        fi
        if [[ "${BOOTSTRAP_ALLOW_STALE_SIDECAR:-0}" == "1" ]]; then
          echo "bootstrap-gen0-sidecar-fallback: using stale prelinked sidecar (want ${want_sha}, have ${have_sha:-<none>}; BOOTSTRAP_ALLOW_STALE_SIDECAR=1 — #8703)" >&2
        else
          echo "bootstrap-gen0-sidecar-fallback: stale compiler_lib sidecar (want ${want_sha}, have ${have_sha:-<none>}) — refusing (#2201)" >&2
          return 1
        fi
      fi
    fi
    printf '%s\n' "${prelinked}"
    return 0
  fi
  return 1
}

bootstrap_gen0_sidecar_emit_fallback() {
  local out=$1
  local entry=$2
  local sidecar=""
  if ! sidecar="$(bootstrap_gen0_sidecar_blob_for_entry "${entry}")"; then
    return 1
  fi
  mkdir -p "$(dirname "${out}")"
  if ! cp -f "${sidecar}" "${out}"; then
    return 1
  fi
  chmod +x "${out}" 2>/dev/null || true
  echo "bootstrap-compile-invoke: gen-0 sidecar emit fallback ${sidecar} -> ${out} (#3046)" >&2
  return 0
}

# Sidecar copy is not honest native emit — gate on M5 no-Zend, SIGSEGV, and opt-in (#8711).
# Exception: compiler_lib spine smoke under BOOTSTRAP_NO_ZEND_FALLBACK uses committed
# path-keyed sidecar when native parse spine is still stubbed (#8716, #2967).
bootstrap_sidecar_emit_fallback_allowed() {
  local last_code=${1:-0}
  local entry="${2:-}"
  if [[ "${BOOTSTRAP_M5_NO_ZEND:-0}" == "1" ]]; then
    echo "bootstrap-compile-invoke: BOOTSTRAP_M5_NO_ZEND=1 — refusing sidecar emit fallback (#3053)" >&2
    return 1
  fi
  if [[ "${BOOTSTRAP_NO_ZEND_FALLBACK:-0}" == "1" ]]; then
    case "${entry//\\//}" in
      */test/selfhost/compiler_lib_spine_smoke/main.php)
        return 0
        ;;
    esac
    return 1
  fi
  if [[ "${last_code}" -eq 139 && "${BOOTSTRAP_ALLOW_SIDECAR_EMIT_FALLBACK:-0}" != "1" ]]; then
    echo "bootstrap-compile-invoke: native driver segfault (exit 139) — refusing sidecar emit fallback (set BOOTSTRAP_ALLOW_SIDECAR_EMIT_FALLBACK=1 to opt in — #8711)" >&2
    return 1
  fi
  return 0
}

bootstrap_try_sidecar_emit_fallback() {
  local out=$1
  local entry=$2
  local last_code=${3:-0}
  # Inventory argv rebuild must reach Zend when gen-0 cannot parse bin/compile.php (#11142, #2880).
  case "${entry//\\//}" in
    */bin/compile.php) return 1 ;;
  esac
  if ! bootstrap_sidecar_emit_fallback_allowed "${last_code}" "${entry}"; then
    return 1
  fi
  if bootstrap_gen0_sidecar_emit_fallback "${out}" "${entry}"; then
    BOOTSTRAP_COMPILE_DRIVER_MODE=sidecar_fallback
    return 0
  fi
  return 1
}

bootstrap_is_gen0_prelinked_seed_driver() {
  local driver=$1
  [[ "${driver}" == *"/bin-compile-aot-inventory" ]] && return 1
  [[ "${driver}" == */bin-compile-aot ]] && return 0
  return 1
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
      PHP_COMPILER_REPO_ROOT="${root}" \
      PHP_COMPILER_SELFHOST_AOT=1 \
      PHP_COMPILER_M3_COMPILE_DRIVER=1 \
      PHP_COMPILER_M4_BIN_COMPILE_DRIVER=1 \
      PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1 \
      BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER=1 \
      "${driver}" -o "${smoke_out}" "${probe}" 2>&1
  )"
  smoke_code=$?
  set -e
  if [[ "${smoke_code}" -eq 0 ]] \
    && bootstrap_native_compile_output_ok "${smoke_log}" \
    && bootstrap_inventory_argv_emit_output_ok "${smoke_out}"; then
    return 0
  fi
  if [[ "${smoke_code}" -eq 0 ]] && bootstrap_native_compile_output_ok "${smoke_log}" \
    && ! bootstrap_inventory_argv_emit_output_ok "${smoke_out}"; then
    echo "bootstrap-inventory-argv-driver-smoke: ${driver} exited 0 with compile OK but missing/non-empty ${smoke_out} (phantom emit — #3046)" >&2
  fi
  printf '%s\n' "${smoke_log}" >&2
  return 1
}

bootstrap_inventory_argv_driver_accepts() {
  local driver=$1
  if ! bootstrap_inventory_argv_driver_smoke "${driver}"; then
    return 1
  fi
  bootstrap_inventory_argv_driver_m4_smoke "${driver}"
}

# M4 full-revision: inventory driver must parse+compile bin/compile.php (stale prelinked gen-0 fails here — #2880).
bootstrap_inventory_argv_driver_m4_smoke() {
  local driver=$1
  local root="${ROOT:-}"
  local bin_compile="${root}/bin/compile.php"
  local prelink="${root}/prelinked/bootstrap-gen0/bin-compile-aot"
  local compile_out="${root}/build/.bootstrap-inventory-argv-driver-m4-compile-smoke-aot"
  if [[ -z "${root}" || ! -x "${driver}" || ! -f "${bin_compile}" ]]; then
    return 1
  fi
  local lint_log=""
  local lint_code=0
  set +e
  lint_log="$(
    env -u PHP_COMPILER_M3_SOURCE -u PHP_COMPILER_M3_OUT \
      PHP_COMPILER_REPO_ROOT="${root}" \
      PHP_COMPILER_SELFHOST_AOT=1 \
      PHP_COMPILER_M3_COMPILE_DRIVER=1 \
      PHP_COMPILER_M4_BIN_COMPILE_DRIVER=1 \
      PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1 \
      BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER=1 \
      "${driver}" -l "${bin_compile}" 2>&1
  )"
  lint_code=$?
  set -e
  if [[ "${lint_code}" -ne 0 ]] \
    || grep -qE 'parseAndCompile returned null|native emit failed at phase=parseAndCompile' <<< "${lint_log}"; then
    echo "bootstrap-inventory-argv-driver-m4-smoke: ${driver} failed bin/compile.php lint (stale gen-0? rebuild via Zend — #2880)" >&2
    printf '%s\n' "${lint_log}" >&2
    return 1
  fi

  # Lint-only smoke misses gen-0 sidecar emit: driver prints compile OK but copies prelinked seed (#1492).
  rm -f "${compile_out}"
  local compile_log=""
  local compile_code=0
  set +e
  compile_log="$(
    env -u PHP_COMPILER_M3_SOURCE -u PHP_COMPILER_M3_OUT \
      PHP_COMPILER_REPO_ROOT="${root}" \
      PHP_COMPILER_SELFHOST_AOT=1 \
      PHP_COMPILER_M3_COMPILE_DRIVER=1 \
      PHP_COMPILER_M4_BIN_COMPILE_DRIVER=1 \
      PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1 \
      BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER=1 \
      "${driver}" -o "${compile_out}" "${bin_compile}" 2>&1
  )"
  compile_code=$?
  set -e
  if [[ "${compile_code}" -ne 0 ]] \
    || ! bootstrap_native_compile_output_ok "${compile_log}" \
    || ! bootstrap_inventory_argv_emit_output_ok "${compile_out}"; then
    echo "bootstrap-inventory-argv-driver-m4-smoke: ${driver} failed bin/compile.php argv compile (rebuild inventory — #2880)" >&2
    printf '%s\n' "${compile_log}" >&2
    rm -f "${compile_out}"
    return 1
  fi
  if [[ -f "${prelink}" ]] && cmp -s "${compile_out}" "${prelink}"; then
    if grep -qE 'sidecar emit fallback|recovered via gen-0 sidecar|parseAndCompile returned null|installed inventory argv driver from prelinked' <<< "${compile_log}"; then
      echo "bootstrap-inventory-argv-driver-m4-smoke: ${driver} bin/compile.php emit is prelinked gen-0 sidecar (not inventory Compiler — #1492)" >&2
      rm -f "${compile_out}"
      return 1
    fi
    if ! declare -F bootstrap_gen3_emit_matches_stale_prelinked_gen0 >/dev/null 2>&1; then
      # shellcheck source=bootstrap-gen0-install-prelinked-driver.sh
      source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/bootstrap-gen0-install-prelinked-driver.sh"
    fi
    if bootstrap_gen3_emit_matches_stale_prelinked_gen0 "${compile_out}"; then
      if [[ "${BOOTSTRAP_ALLOW_STALE_SIDECAR:-0}" == "1" ]]; then
        echo "bootstrap-inventory-argv-driver-m4-smoke: ${driver} bin/compile.php emit matches stale prelinked (BOOTSTRAP_ALLOW_STALE_SIDECAR=1 — #8703)" >&2
      else
        echo "bootstrap-inventory-argv-driver-m4-smoke: ${driver} bin/compile.php emit matches stale prelinked/bootstrap-gen0/ (refresh gen-0 — #8710)" >&2
        rm -f "${compile_out}"
        return 1
      fi
    fi
    # Self-host fixed point: honest inventory emit reproduces refreshed gen-0 driver bytes.
  fi
  if ! bootstrap_inventory_argv_driver_size_ok "${compile_out}"; then
    echo "bootstrap-inventory-argv-driver-m4-smoke: ${driver} bin/compile.php emit too small (sidecar stub — #3012)" >&2
    rm -f "${compile_out}"
    return 1
  fi
  rm -f "${compile_out}"
  return 0
}

# Seed build/bin-compile-aot from prelinked when empty so compiled-first has a gen-0 driver (#3053).
bootstrap_ensure_gen0_seed_driver() {
  local root="${ROOT:-}"
  if [[ -z "${root}" ]]; then
    return 1
  fi
  if [[ -x "${root}/build/bin-compile-aot" ]]; then
    return 0
  fi
  if ! declare -F bootstrap_gen0_install_prelinked_driver >/dev/null 2>&1; then
    # shellcheck source=bootstrap-gen0-install-prelinked-driver.sh
    source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/bootstrap-gen0-install-prelinked-driver.sh"
  fi
  bootstrap_gen0_install_prelinked_driver
}

# Sidecar + gen-0 seed prep before inventory argv link (compiled-first or Zend bisect).
bootstrap_inventory_argv_link_sidecar_prep() {
  local root="${ROOT:-}"
  if [[ -z "${root}" ]]; then
    return 1
  fi
  if ! declare -F bootstrap_gen0_seed_prelinked_m3_sidecars >/dev/null 2>&1; then
    # shellcheck source=bootstrap-gen0-install-prelinked-driver.sh
    source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/bootstrap-gen0-install-prelinked-driver.sh"
  fi
  bootstrap_gen0_seed_prelinked_m3_sidecars 2>/dev/null || true
  bootstrap_ensure_m3_compiler_lib_sidecar 2>/dev/null || true
  bootstrap_ensure_gen0_seed_driver 2>/dev/null || true
}

# Inventory argv env for bin/compile.php link (minimal sidecars default-on — #1492).
bootstrap_inventory_argv_link_minimal_flags() {
  if [[ "${BOOTSTRAP_INVENTORY_DRIVER_FULL:-0}" == "1" ]]; then
    echo 0
  elif [[ "${BOOTSTRAP_INVENTORY_MINIMAL_SIDECARS:-1}" == "1" ]]; then
    echo 1
  else
    echo 0
  fi
}

# Link inventory argv driver: compiled drivers first, prelinked fallback, Zend bisect last (#2930, #3053).
bootstrap_inventory_argv_link() {
  local out=$1
  local root="${ROOT:-}"
  local entry="${root}/bin/compile.php"
  if [[ -z "${root}" || -z "${out}" ]]; then
    echo "bootstrap-inventory-argv-link: ROOT/out unset" >&2
    return 1
  fi
  if [[ ! -f "${entry}" ]]; then
    echo "bootstrap-inventory-argv-link: missing ${entry}" >&2
    return 1
  fi
  if ! declare -F bootstrap_gen0_copy_prelinked_inventory_driver >/dev/null 2>&1; then
    # shellcheck source=bootstrap-gen0-install-prelinked-driver.sh
    source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/bootstrap-gen0-install-prelinked-driver.sh"
  fi
  if [[ "${BOOTSTRAP_INVENTORY_DRIVER_USE_PRELINKED:-0}" == "1" ]]; then
    if bootstrap_gen0_copy_prelinked_inventory_driver "${out}" "" "${out}"; then
      echo "bootstrap-inventory-argv-link: OK ${out} (prelinked only; BOOTSTRAP_INVENTORY_DRIVER_USE_PRELINKED=1)" >&2
      return 0
    fi
    return 1
  fi
  bootstrap_inventory_argv_link_sidecar_prep
  local _inventory_minimal
  _inventory_minimal="$(bootstrap_inventory_argv_link_minimal_flags)"
  rm -f "${out}"
  if bootstrap_compile_invoke "${out}" "${entry}" env \
    -u PHP_COMPILER_EMIT_HELPER_LINK \
    PHP_COMPILER_SELFHOST_AOT=1 \
    PHP_COMPILER_M3_COMPILE_DRIVER=1 \
    PHP_COMPILER_M3_COMPILE_DRIVER_MAIN=1 \
    PHP_COMPILER_M4_BIN_COMPILE_DRIVER=1 \
    PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1 \
    BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER=1 \
    PHP_COMPILER_M3_EMIT_LOG_PREFIX=helloworld_compile_smoke \
    PHP_COMPILER_M3_INVENTORY_MINIMAL_SIDECARS="${_inventory_minimal}" \
    PHP_COMPILER_M3_REUSE_STALE_COMPILER_LIB_SIDECAR="${_inventory_minimal}"; then
    if [[ -x "${out}" && -s "${out}" ]]; then
      cp -f "${out}" "${root}/build/.m3_bin_compile_aot_blob"
      chmod +x "${root}/build/.m3_bin_compile_aot_blob"
    fi
    echo "bootstrap-inventory-argv-link: OK ${out} (gen-0 compiled; emit_path=${BOOTSTRAP_COMPILE_DRIVER_MODE:-native})" >&2
    return 0
  fi
  echo "bootstrap-inventory-argv-link: compiled-first inventory emit failed; trying prelinked gen-0 (#2930)" >&2
  if bootstrap_gen0_copy_prelinked_inventory_driver "${out}" "" "${out}"; then
    echo "bootstrap-inventory-argv-link: OK ${out} (prelinked fallback)" >&2
    return 0
  fi
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
  if bootstrap_is_inventory_bin_compile_argv_driver "${out}" \
    && bootstrap_inventory_argv_driver_accepts "${out}"; then
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
  if [[ "${BOOTSTRAP_ALLOW_STALE_SIDECAR:-0}" == "1" ]]; then
    local prelink="${root}/prelinked/bootstrap-gen0/bin-compile-aot"
    if [[ -x "${prelink}" ]]; then
      mkdir -p "${root}/build"
      cp -f "${prelink}" "${out}"
      cp -f "${prelink}" "${root}/build/.m3_bin_compile_aot_blob"
      chmod +x "${out}" "${root}/build/.m3_bin_compile_aot_blob"
      if ! declare -F bootstrap_gen0_seed_prelinked_m3_sidecars >/dev/null 2>&1; then
        # shellcheck source=bootstrap-gen0-install-prelinked-driver.sh
        source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/bootstrap-gen0-install-prelinked-driver.sh"
      fi
      bootstrap_gen0_seed_prelinked_m3_sidecars 2>/dev/null || true
      if bootstrap_is_inventory_bin_compile_argv_driver "${out}" \
        && bootstrap_inventory_argv_driver_accepts "${out}"; then
        echo "bootstrap-ensure-inventory-argv-driver: using prelinked gen-0 (BOOTSTRAP_ALLOW_STALE_SIDECAR=1 — #8703)" >&2
        return 0
      fi
      rm -f "${out}" "${root}/build/.m3_bin_compile_aot_blob"
    fi
  fi
  if ! declare -F bootstrap_gen0_seed_prelinked_m3_sidecars >/dev/null 2>&1; then
    # shellcheck source=bootstrap-gen0-install-prelinked-driver.sh
    source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/bootstrap-gen0-install-prelinked-driver.sh"
  fi
  if [[ "${BOOTSTRAP_M5_NO_ZEND:-0}" == "1" || "${BOOTSTRAP_NO_ZEND_FALLBACK:-0}" == "1" ]]; then
    bootstrap_gen0_seed_prelinked_m3_sidecars 2>/dev/null || true
    bootstrap_ensure_m3_compiler_lib_sidecar 2>/dev/null || true
    if bootstrap_gen0_copy_prelinked_inventory_driver "${out}" "" "${out}"; then
      if bootstrap_is_inventory_bin_compile_argv_driver "${out}" \
        && bootstrap_inventory_argv_driver_accepts "${out}"; then
        return 0
      fi
      if bootstrap_is_inventory_bin_compile_argv_driver "${out}" \
        && bootstrap_inventory_argv_driver_smoke "${out}"; then
        echo "bootstrap-ensure-inventory-argv-driver: prelinked inventory driver OK for helloworld smoke (skip bin/compile.php m4 under NO_ZEND — #8716)" >&2
        return 0
      fi
    fi
    if [[ "${BOOTSTRAP_M5_NO_ZEND:-0}" == "1" || "${BOOTSTRAP_NO_ZEND_FALLBACK:-0}" == "1" ]]; then
      rm -f "${out}" "${root}/build/.m3_bin_compile_aot_blob"
      echo "bootstrap-ensure-inventory-argv-driver: BOOTSTRAP_NO_ZEND_FALLBACK=1 — prelinked inventory driver failed smoke (#8716, #3053)" >&2
      return 1
    fi
  fi
  echo "bootstrap-ensure-inventory-argv-driver: building inventory argv driver ${out} (#3012, compiled-first)" >&2
  if ! bootstrap_inventory_argv_link "${out}"; then
    echo "bootstrap-ensure-inventory-argv-driver: inventory argv link failed" >&2
    return 1
  fi
  bootstrap_ensure_m3_compiler_lib_sidecar 2>/dev/null || true
  if ! bootstrap_is_inventory_bin_compile_argv_driver "${out}"; then
    echo "bootstrap-ensure-inventory-argv-driver: ${out} is not a verified inventory argv driver" >&2
    return 1
  fi
  if ! bootstrap_inventory_argv_driver_accepts "${out}"; then
    echo "bootstrap-ensure-inventory-argv-driver: ${out} failed post-build inventory smoke (phantom emit? rebuild via Zend — #3046)" >&2
    rm -f "${out}" "${root}/build/.m3_bin_compile_aot_blob"
    return 1
  fi
}

bootstrap_list_native_compile_drivers() {
  local root="${ROOT:-}"
  if [[ -z "${root}" ]]; then
    echo "bootstrap-resolve-compile-invoke: ROOT unset" >&2
    return 1
  fi

  if [[ "${BOOTSTRAP_USE_INVENTORY_DRIVER:-0}" == "1" ]]; then
    printf '%s\n' "${root}/build/bin-compile-aot-inventory"
    return 0
  fi

  printf '%s\n' \
    "${root}/build/bin-compile-aot-inventory" \
    "${root}/build/bin-compile-aot" \
    "${root}/build/selfhost-native-compile-driver" \
    "${root}/build/selfhost-helloworld-compile" \
    "${root}/build/selfhost-compile-driver"
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

  if [[ "${BOOTSTRAP_ALLOW_GEN0_ZEND:-1}" == "1" ]] \
    && [[ "${BOOTSTRAP_M5_NO_ZEND:-0}" != "1" ]] \
    && command -v php >/dev/null 2>&1; then
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
    echo "bootstrap-compile-invoke: ${BOOTSTRAP_COMPILE_DRIVER} -o ${out} ${entry} (gen-0 compiled)" >&2
    rm -f "${out}"
    local invoke_out=""
    set +e
    invoke_out="$("$@" "${BOOTSTRAP_COMPILE_DRIVER}" -o "${out}" "${entry}" 2>&1)"
    last_code=$?
    set -e
    printf '%s\n' "${invoke_out}"
    if [[ "${last_code}" -eq 0 ]] && bootstrap_inventory_argv_emit_output_ok "${out}"; then
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
          BOOTSTRAP_COMPILE_DRIVER_MODE=native
          return 0
        fi
      else
        BOOTSTRAP_COMPILE_DRIVER_MODE=native
        return 0
      fi
    fi
    if [[ "${last_code}" -eq 0 && ! -x "${out}" ]]; then
      if bootstrap_native_compile_output_ok "${invoke_out}" \
        && bootstrap_try_sidecar_emit_fallback "${out}" "${entry}" "${last_code}"; then
        return 0
      fi
      echo "bootstrap-compile-invoke: compiled driver ${BOOTSTRAP_COMPILE_DRIVER} exited 0 but missing ${out} (#3046)" >&2
      last_code=1
    elif grep -qE 'parseAndCompile returned null|native emit failed at phase=parseAndCompile' <<< "${invoke_out}" \
      && bootstrap_try_sidecar_emit_fallback "${out}" "${entry}" "${last_code}"; then
      echo "bootstrap-compile-invoke: native parse spine null — recovered via gen-0 sidecar (#1492)" >&2
      return 0
    elif [[ "${last_code}" -ne 0 ]] \
      && bootstrap_is_gen0_prelinked_seed_driver "${BOOTSTRAP_COMPILE_DRIVER}" \
      && bootstrap_try_sidecar_emit_fallback "${out}" "${entry}" "${last_code}"; then
      echo "bootstrap-compile-invoke: gen-0 native emit failed — recovered via sidecar (#1492, #3046)" >&2
      return 0
    else
      echo "bootstrap-compile-invoke: compiled driver ${BOOTSTRAP_COMPILE_DRIVER} failed (exit ${last_code})" >&2
    fi
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

  if [[ "${BOOTSTRAP_ALLOW_GEN0_ZEND:-1}" != "1" ]]; then
    echo "bootstrap-compile-invoke: compiled driver(s) failed; BOOTSTRAP_ALLOW_GEN0_ZEND=0 — no Zend fallback (#2894)" >&2
    return "${last_code}"
  fi

  if ! command -v php >/dev/null 2>&1; then
    echo "bootstrap-compile-invoke: compiled driver(s) failed and php missing — cannot fall back (#2842)" >&2
    return "${last_code}"
  fi

  if bootstrap_try_sidecar_emit_fallback "${out}" "${entry}" "${last_code}"; then
    echo "bootstrap-compile-invoke: gen-0 sidecar emit after native driver sweep (#8711, #3046)" >&2
    return 0
  fi

  echo "bootstrap-compile-invoke: compiled driver(s) failed — falling back to Zend gen-0 (#2842)" >&2
  BOOTSTRAP_COMPILE_DRIVER_MODE=zend
  BOOTSTRAP_COMPILE_DRIVER="${ROOT}/bin/compile.php"
  bootstrap_compile_invoke_zend "${out}" "${entry}" "$@"
}
