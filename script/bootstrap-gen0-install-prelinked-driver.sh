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
