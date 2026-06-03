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
  local prelinked_blob="${root}/prelinked/bootstrap-gen0/.m3_bin_compile_aot_blob"
  if [[ -f "${prelinked_blob}" && -s "${prelinked_blob}" ]]; then
    cp -f "${prelinked_blob}" "${blob}"
  else
    cp -f "${seed}" "${blob}"
  fi
  cp -f "${minimal_seed}" "${minimal_blob}"
  chmod +x "${blob}" "${minimal_blob}"
  return 0
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
  mkdir -p "${root}/build" "$(dirname "${aot_out}")"
  cp -f "${seed}" "${aot_out}"
  chmod +x "${aot_out}"
  bootstrap_gen0_seed_prelinked_m3_sidecars || true
  if [[ -n "${emit_helper}" ]]; then
    cp -f "${aot_out}" "${emit_helper}"
    chmod +x "${emit_helper}"
  fi
  if [[ -n "${inventory_argv}" ]]; then
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
  if [[ -x "${out}" && -f "${minimal_blob}" ]]; then
    return 0
  fi

  local seed
  seed="$(bootstrap_gen0_prelinked_driver_path)"
  if [[ ! -f "${seed}" || ! -s "${seed}" ]]; then
    echo "bootstrap-gen0-install: missing committed driver ${seed} (#3053)" >&2
    return 1
  fi

  local minimal_seed="${root}/prelinked/bootstrap-gen0/compiler_minimal_aot_blob"
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
