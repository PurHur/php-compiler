#!/usr/bin/env bash
# Install committed gen-0 native compile driver when build/ is empty (#3053, #2894).
#
# Usage (sourced from bootstrap-selfhost-link.sh after ROOT is set):
#   bootstrap_gen0_install_prelinked_driver
#
# Returns 0 when build/bin-compile-aot is present (pre-existing or installed).
set -euo pipefail

bootstrap_gen0_prelinked_driver_path() {
  echo "${ROOT}/prelinked/bootstrap-gen0/bin-compile-aot"
}

bootstrap_gen0_prelinked_driver_ready() {
  local seed
  seed="$(bootstrap_gen0_prelinked_driver_path)"
  [[ -f "${seed}" && -s "${seed}" ]]
}

bootstrap_gen0_prelinked_minimal_sidecar_path() {
  echo "${ROOT}/prelinked/bootstrap-gen0/compiler_minimal_aot_blob"
}

# Docker-built gen-0 embeds /compiler/build/.m3_* sidecar paths — unusable on host harness (#3046).
bootstrap_gen0_prelinked_sidecar_looks_stale() {
  local blob=$1
  [[ -f "${blob}" && -s "${blob}" ]] || return 1
  if strings "${blob}" 2>/dev/null | grep -q '/compiler/build/.m3_'; then
    return 0
  fi
  if strings "${blob}" 2>/dev/null | grep -q '/compiler/bin/compile.php'; then
    return 0
  fi
  return 1
}

# True when build artifacts byte-match committed prelinked seeds (#1492 stale driver-smoke overwrite).
bootstrap_gen0_installed_driver_matches_prelinked() {
  local out=$1
  local seed
  seed="$(bootstrap_gen0_prelinked_driver_path)"
  [[ -x "${out}" && -f "${seed}" && -s "${seed}" ]] || return 1
  cmp -s "${out}" "${seed}"
}

bootstrap_gen0_installed_minimal_sidecar_matches_prelinked() {
  local blob=$1
  local seed
  seed="$(bootstrap_gen0_prelinked_minimal_sidecar_path)"
  [[ -f "${blob}" && -f "${seed}" && -s "${seed}" ]] || return 1
  cmp -s "${blob}" "${seed}"
}

# Prelinked gen-0 argv drivers bake /home/ai/php-compiler/build/.m3_* link-time paths (#1492).
# Symlink that prefix to the live build/ tree so __compiler_copy sidecar emit succeeds.
bootstrap_ensure_prelinked_sidecar_path_symlink() {
  local root="${ROOT:-}"
  local baked="/home/ai/php-compiler/build"
  if [[ -z "${root}" || ! -d "${root}/build" ]]; then
    return 1
  fi
  if [[ -e "${baked}" && ! -L "${baked}" ]]; then
    return 0
  fi
  mkdir -p "$(dirname "${baked}")" 2>/dev/null || true
  ln -sfn "${root}/build" "${baked}" 2>/dev/null || true
}

# Seed link-time sidecars so Zend inventory emit skips bin/compile.php host-compile (#2930).
bootstrap_gen0_seed_prelinked_m3_sidecars() {
  local root="${ROOT:-}"
  if [[ -z "${root}" ]]; then
    return 1
  fi
  local seed blob minimal_seed minimal_blob
  seed="$(bootstrap_gen0_prelinked_driver_path)"
  if [[ ! -f "${seed}" || ! -s "${seed}" ]]; then
    return 1
  fi
  minimal_seed="${root}/prelinked/bootstrap-gen0/compiler_minimal_aot_blob"
  if [[ ! -f "${minimal_seed}" || ! -s "${minimal_seed}" ]]; then
    return 1
  fi
  mkdir -p "${root}/build"
  blob="${root}/build/.m3_bin_compile_aot_blob"
  minimal_blob="${root}/build/.m3_compiler_minimal_aot_blob"
  local inventory="${root}/build/bin-compile-aot-inventory"
  local prelinked_blob="${root}/prelinked/bootstrap-gen0/.m3_bin_compile_aot_blob"
  if [[ -x "${inventory}" && -s "${inventory}" ]]; then
    cp -f "${inventory}" "${blob}"
  elif [[ -f "${prelinked_blob}" && -s "${prelinked_blob}" ]] \
    && ! bootstrap_gen0_prelinked_sidecar_looks_stale "${prelinked_blob}"; then
    cp -f "${prelinked_blob}" "${blob}"
  else
    cp -f "${seed}" "${blob}"
  fi
  cp -f "${minimal_seed}" "${minimal_blob}"
  chmod +x "${blob}" "${minimal_blob}"
  local compiler_lib_seed="${root}/prelinked/bootstrap-gen0/compiler_lib_aot_blob"
  if [[ -f "${compiler_lib_seed}" && -s "${compiler_lib_seed}" ]]; then
    cp -f "${compiler_lib_seed}" "${root}/build/.m3_compiler_lib_aot_blob"
    chmod +x "${root}/build/.m3_compiler_lib_aot_blob"
    if [[ -f "${root}/prelinked/bootstrap-gen0/.m3_compiler_lib_sidecar.sha" ]]; then
      cp -f "${root}/prelinked/bootstrap-gen0/.m3_compiler_lib_sidecar.sha" \
        "${root}/build/.m3_compiler_lib_sidecar.sha"
    fi
  fi
  # Inventory argv emit needs the full M3 sidecar set baked at bin/compile.php link time (#2880, #1492).
  local prelinked_dir="${root}/prelinked/bootstrap-gen0"
  local sidecar
  shopt -s nullglob
  for sidecar in "${prelinked_dir}"/.m3_*; do
    [[ -f "${sidecar}" && -s "${sidecar}" ]] || continue
    if bootstrap_gen0_prelinked_sidecar_looks_stale "${sidecar}"; then
      continue
    fi
    cp -f "${sidecar}" "${root}/build/$(basename "${sidecar}")"
    chmod +x "${root}/build/$(basename "${sidecar}")" 2>/dev/null || true
  done
  shopt -u nullglob
  bootstrap_ensure_prelinked_sidecar_path_symlink 2>/dev/null || true
  return 0
}

# Copy committed gen-0 spine AOT blob (no Zend/inventory compile — feedback-loop fast path #2201).
bootstrap_copy_prelinked_compiler_lib_spine_blob() {
  local out=$1
  local root="${ROOT:-}"
  local prelinked="${root}/prelinked/bootstrap-gen0/compiler_lib_aot_blob"
  local stamp_src="${root}/prelinked/bootstrap-gen0/.m3_compiler_lib_sidecar.sha"
  local stamp_dst="${root}/build/.m3_compiler_lib_sidecar.sha"
  if [[ -z "${root}" || -z "${out}" || ! -f "${prelinked}" || ! -s "${prelinked}" ]]; then
    return 1
  fi
  mkdir -p "$(dirname "${out}")" "${root}/build"
  cp -f "${prelinked}" "${out}"
  chmod +x "${out}"
  cp -f "${prelinked}" "${root}/build/.m3_compiler_lib_aot_blob"
  chmod +x "${root}/build/.m3_compiler_lib_aot_blob"
  if [[ -f "${stamp_src}" ]]; then
    cp -f "${stamp_src}" "${stamp_dst}"
  fi
  return 0
}

# SHA-1 of M2 compiler_lib_spine_smoke entry (sidecar + inventory argv driver must match).
bootstrap_compiler_lib_spine_entry_sha() {
  local root="${ROOT:-}"
  local entry="${root}/test/selfhost/compiler_lib_spine_smoke/main.php"
  if [[ -z "${root}" || ! -f "${entry}" ]]; then
    return 1
  fi
  sha1sum "${entry}" | awk '{print $1}'
}

# Honest Zend spine compile for compiler_lib sidecar refresh (#8559).
# mode=sidecar keeps PHP_COMPILER_M3_EMIT_SIDECAR_RECURSION_GUARD for blob regen;
# mode=full omits it for selfhost-lib-spine-smoke link (LLVM verify path — #8559).
bootstrap_compiler_lib_honest_zend_compile() {
  local out=$1
  local entry=$2
  local mode="${3:-sidecar}"
  local root="${ROOT:-}"
  if [[ -z "${root}" || -z "${out}" || -z "${entry}" || ! -f "${entry}" ]]; then
    return 1
  fi
  if ! command -v php >/dev/null 2>&1; then
    return 1
  fi
  mkdir -p "$(dirname "${out}")"
  rm -f "${out}"
  # ci_apply_llvm_memory_env pins 4096M; full-spine sidecar host-compile OOMs below 8GB (#8559).
  local mem_limit="8192M"
  if [[ "${mode}" == "sidecar" ]]; then
    env PHP_COMPILER_M3_EMIT_SIDECAR_RECURSION_GUARD=1 \
      PHP_COMPILER_SELFHOST_AOT=1 \
      PHP_COMPILER_LIB_SPINE_BUNDLE=1 \
      PHP_COMPILER_MEMORY_LIMIT="${mem_limit}" \
      php -d "memory_limit=${mem_limit}" \
      "${root}/bin/compile.php" -o "${out}" "${entry}"
    return $?
  fi
  # Verified full-spine path: memory limit only (no SELFHOST_AOT / LIB_SPINE_BUNDLE — #8559).
  env PHP_COMPILER_MEMORY_LIMIT="${mem_limit}" \
    php -d "memory_limit=${mem_limit}" \
    "${root}/bin/compile.php" -o "${out}" "${entry}"
}

# True when committed prelinked gen-0 compiler_lib stamp lags the live spine entry (#8710, #8559).
bootstrap_prelinked_gen0_compiler_lib_stamp_stale() {
  local root="${ROOT:-}"
  if [[ -z "${root}" ]]; then
    return 1
  fi
  local stamp="${root}/prelinked/bootstrap-gen0/.m3_compiler_lib_sidecar.sha"
  if [[ ! -f "${stamp}" ]]; then
    return 0
  fi
  local want_sha have_sha
  want_sha="$(bootstrap_compiler_lib_spine_entry_sha)" || return 0
  have_sha="$(tr -d '\n' <"${stamp}")"
  [[ "${want_sha}" != "${have_sha}" ]]
}

# Gen-3 argv driver byte-matches stale prelinked gen-0 (sidecar copy, not inventory emit — #8710).
bootstrap_gen3_emit_matches_stale_prelinked_gen0() {
  local gen3=$1
  local root="${ROOT:-}"
  local prelinked="${root}/prelinked/bootstrap-gen0/bin-compile-aot"
  if [[ -z "${root}" || ! -f "${gen3}" || ! -f "${prelinked}" ]]; then
    return 1
  fi
  cmp -s "${gen3}" "${prelinked}" || return 1
  bootstrap_prelinked_gen0_compiler_lib_stamp_stale
}

# Ensure build/.m3_compiler_lib_aot_blob matches current spine entry (#3012, #2967).
bootstrap_ensure_m3_compiler_lib_sidecar() {
  local root="${ROOT:-}"
  local entry="${root}/test/selfhost/compiler_lib_spine_smoke/main.php"
  local blob="${root}/build/.m3_compiler_lib_aot_blob"
  local stamp="${root}/build/.m3_compiler_lib_sidecar.sha"
  if [[ -z "${root}" || ! -f "${entry}" ]]; then
    echo "bootstrap-ensure-m3-compiler-lib-sidecar: missing spine entry ${entry}" >&2
    return 1
  fi
  local want_sha
  want_sha="$(bootstrap_compiler_lib_spine_entry_sha)" || return 1
  if [[ -f "${blob}" && -s "${blob}" && -f "${stamp}" && "$(cat "${stamp}" 2>/dev/null)" == "${want_sha}" ]]; then
    return 0
  fi
  bootstrap_gen0_seed_prelinked_m3_sidecars 2>/dev/null || true
  if [[ -f "${blob}" && -s "${blob}" && -f "${stamp}" && "$(cat "${stamp}" 2>/dev/null)" == "${want_sha}" ]]; then
    return 0
  fi
  # Zend host-compile of full spine often SIGSEGV; keep seeded/prelinked sidecar when stamp is stale (#2967).
  if [[ -f "${blob}" && -s "${blob}" && "${BOOTSTRAP_FORCE_COMPILER_LIB_SIDECAR_REGEN:-0}" != "1" ]]; then
    echo "bootstrap-ensure-m3-compiler-lib-sidecar: using existing ${blob} (stamp stale; BOOTSTRAP_FORCE_COMPILER_LIB_SIDECAR_REGEN=1 to regen)" >&2
    return 0
  fi
  if [[ "${BOOTSTRAP_SKIP_COMPILER_LIB_SIDECAR_REGEN:-0}" == "1" ]]; then
    echo "bootstrap-ensure-m3-compiler-lib-sidecar: stale/missing ${blob} (BOOTSTRAP_SKIP_COMPILER_LIB_SIDECAR_REGEN=1)" >&2
    return 1
  fi
  if ! command -v php >/dev/null 2>&1; then
    echo "bootstrap-ensure-m3-compiler-lib-sidecar: php required to host-compile ${entry}" >&2
    return 1
  fi
  local prelinked_lib="${root}/prelinked/bootstrap-gen0/compiler_lib_aot_blob"
  local regen_tmp="${blob}.regen.$$"
  echo "bootstrap-ensure-m3-compiler-lib-sidecar: compiled-first spine sidecar -> ${blob} (#8559)" >&2
  mkdir -p "${root}/build"
  rm -f "${regen_tmp}"
  local regen_log regen_code=0
  if ! declare -F bootstrap_compile_invoke >/dev/null 2>&1; then
    # shellcheck source=bootstrap-resolve-compile-invoke.sh
    source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/bootstrap-resolve-compile-invoke.sh"
  fi
  bootstrap_inventory_argv_link_sidecar_prep 2>/dev/null || true
  set +e
  regen_log="$(
    bootstrap_compile_invoke "${regen_tmp}" "${entry}" env \
      PHP_COMPILER_M3_EMIT_SIDECAR_RECURSION_GUARD=1 \
      PHP_COMPILER_SELFHOST_AOT=1 \
      PHP_COMPILER_LIB_SPINE_BUNDLE=1 \
      PHP_COMPILER_MEMORY_LIMIT="${mem_limit}" 2>&1
  )"
  regen_code=$?
  set -e
  if [[ "${regen_code}" -eq 0 && -f "${regen_tmp}" && -s "${regen_tmp}" ]]; then
    mv -f "${regen_tmp}" "${blob}"
    chmod +x "${blob}"
    printf '%s' "${want_sha}" >"${stamp}"
    if [[ -f "${prelinked_lib}" ]] && ! cmp -s "${blob}" "${prelinked_lib}"; then
      cp -f "${blob}" "${prelinked_lib}"
      chmod +x "${prelinked_lib}"
      printf '%s' "${want_sha}" >"${root}/prelinked/bootstrap-gen0/.m3_compiler_lib_sidecar.sha"
      echo "bootstrap-ensure-m3-compiler-lib-sidecar: refreshed prelinked ${prelinked_lib} (#8559)" >&2
    fi
    return 0
  fi
  rm -f "${regen_tmp}"
  printf '%s\n' "${regen_log}" >&2
  echo "bootstrap-ensure-m3-compiler-lib-sidecar: compiled-first host-compile failed (exit ${regen_code}); trying Zend (#8559)" >&2
  rm -f "${regen_tmp}"
  set +e
  # Do not set PHP_COMPILER_CLI_SPINE_BUNDLE here — that skips bin/compile.php argv dispatch (#1492).
  regen_log="$(bootstrap_compiler_lib_honest_zend_compile "${regen_tmp}" "${entry}" sidecar 2>&1)"
  regen_code=$?
  set -e
  if [[ "${regen_code}" -eq 0 && -f "${regen_tmp}" && -s "${regen_tmp}" ]]; then
    mv -f "${regen_tmp}" "${blob}"
    chmod +x "${blob}"
    printf '%s' "${want_sha}" >"${stamp}"
    if [[ -f "${prelinked_lib}" ]] && ! cmp -s "${blob}" "${prelinked_lib}"; then
      cp -f "${blob}" "${prelinked_lib}"
      chmod +x "${prelinked_lib}"
      printf '%s' "${want_sha}" >"${root}/prelinked/bootstrap-gen0/.m3_compiler_lib_sidecar.sha"
      echo "bootstrap-ensure-m3-compiler-lib-sidecar: refreshed prelinked ${prelinked_lib} (#8559)" >&2
    fi
    return 0
  fi
  rm -f "${regen_tmp}"
  printf '%s\n' "${regen_log}" >&2
  echo "bootstrap-ensure-m3-compiler-lib-sidecar: host-compile failed (exit ${regen_code}); inventory argv driver may return parseAndCompile null (#2967)" >&2
  if [[ ! -f "${blob}" || ! -s "${blob}" ]] && [[ -f "${prelinked_lib}" && -s "${prelinked_lib}" ]]; then
    cp -f "${prelinked_lib}" "${blob}"
    chmod +x "${blob}"
    if [[ -f "${root}/prelinked/bootstrap-gen0/.m3_compiler_lib_sidecar.sha" ]]; then
      cp -f "${root}/prelinked/bootstrap-gen0/.m3_compiler_lib_sidecar.sha" "${stamp}"
    fi
    echo "bootstrap-ensure-m3-compiler-lib-sidecar: restored prelinked ${blob} after host-compile failure (#2967)" >&2
    return 0
  fi
  echo "bootstrap-ensure-m3-compiler-lib-sidecar: hint: refresh prelinked/bootstrap-gen0 after spine entry changes, or fix Zend spine AOT (VM/JIT)" >&2
  return 1
}

# Copy committed gen-0 argv driver to inventory outputs (mitigates Zend SIGSEGV on bin/compile.php — #2930).
bootstrap_gen0_copy_prelinked_inventory_driver() {
  local aot_out=$1
  local emit_helper="${2:-}"
  local inventory_argv="${3:-}"
  local root="${ROOT:-}"
  if [[ -z "${root}" || -z "${aot_out}" ]]; then
    return 1
  fi
  local seed
  seed="$(bootstrap_gen0_prelinked_driver_path)"
  if [[ ! -f "${seed}" || ! -s "${seed}" ]]; then
    echo "bootstrap-gen0-install: missing committed driver ${seed} (#2930)" >&2
    return 1
  fi
  if bootstrap_gen0_prelinked_sidecar_looks_stale "${seed}"; then
    echo "bootstrap-gen0-install: prelinked inventory driver has stale /compiler sidecar paths — rebuild via Zend (BOOTSTRAP_INVENTORY_DRIVER_USE_PRELINKED=0) (#3046)" >&2
    return 1
  fi
  mkdir -p "${root}/build" "$(dirname "${aot_out}")"
  cp -f "${seed}" "${aot_out}"
  chmod +x "${aot_out}"
  bootstrap_gen0_seed_prelinked_m3_sidecars || true
  if [[ -n "${emit_helper}" ]]; then
    cp -f "${aot_out}" "${emit_helper}"
    chmod +x "${emit_helper}"
  fi
  if [[ -n "${inventory_argv}" && "${inventory_argv}" != "${aot_out}" ]]; then
    cp -f "${aot_out}" "${inventory_argv}"
    chmod +x "${inventory_argv}"
  fi
  cp -f "${aot_out}" "${root}/build/.m3_bin_compile_aot_blob"
  chmod +x "${root}/build/.m3_bin_compile_aot_blob"
  echo "bootstrap-gen0-install: installed inventory argv driver from prelinked/bootstrap-gen0 (#2930)" >&2
  return 0
}

bootstrap_gen0_install_prelinked_driver() {
  local root="${ROOT:-}"
  if [[ -z "${root}" ]]; then
    echo "bootstrap-gen0-install: ROOT unset" >&2
    return 1
  fi

  local out="${root}/build/bin-compile-aot"
  local blob="${root}/build/.m3_bin_compile_aot_blob"
  local minimal_blob="${root}/build/.m3_compiler_minimal_aot_blob"
  if [[ -x "${out}" && -f "${minimal_blob}" ]] \
    && bootstrap_gen0_installed_driver_matches_prelinked "${out}" \
    && bootstrap_gen0_installed_minimal_sidecar_matches_prelinked "${minimal_blob}"; then
    return 0
  fi
  if [[ -x "${out}" && -f "${minimal_blob}" ]]; then
    echo "bootstrap-gen0-install: stale build/bin-compile-aot (driver-smoke overwrite); reinstalling from prelinked/bootstrap-gen0 (#1492)" >&2
  fi

  local seed
  seed="$(bootstrap_gen0_prelinked_driver_path)"
  if [[ ! -f "${seed}" || ! -s "${seed}" ]]; then
    echo "bootstrap-gen0-install: missing committed driver ${seed} (#3053)" >&2
    return 1
  fi

  local minimal_seed
  minimal_seed="$(bootstrap_gen0_prelinked_minimal_sidecar_path)"
  if [[ ! -f "${minimal_seed}" || ! -s "${minimal_seed}" ]]; then
    echo "bootstrap-gen0-install: missing ${minimal_seed} (M0 sidecar for argv driver — #3053)" >&2
    return 1
  fi

  mkdir -p "${root}/build"
  cp -f "${seed}" "${out}"
  chmod +x "${out}"
  cp -f "${out}" "${blob}"
  chmod +x "${blob}"
  cp -f "${minimal_seed}" "${minimal_blob}"
  chmod +x "${minimal_blob}"
  echo "bootstrap-gen0-install: installed ${out} + compiler_minimal sidecar from prelinked/bootstrap-gen0 (#3053)" >&2
  return 0
}
