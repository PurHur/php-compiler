#!/usr/bin/env bash
# Lowering-source freshness for compiled bootstrap drivers (#21855).
set -euo pipefail

bootstrap_lowering_source_stamp_basename() {
  echo '.bootstrap_lowering_source.sha'
}

bootstrap_lowering_source_build_stamp() {
  local root="${ROOT:-}"
  if [[ -z "${root}" ]]; then
    return 1
  fi
  echo "${root}/build/$(bootstrap_lowering_source_stamp_basename)"
}

bootstrap_lowering_source_prelinked_stamp() {
  local root="${ROOT:-}"
  if [[ -z "${root}" ]]; then
    return 1
  fi
  echo "${root}/prelinked/bootstrap-gen0/$(bootstrap_lowering_source_stamp_basename)"
}

bootstrap_lowering_source_fingerprint() {
  local root="${ROOT:-}"
  if [[ -z "${root}" ]]; then
    echo "bootstrap-lowering-freshness: ROOT unset" >&2
    return 1
  fi
  if [[ -n "${BOOTSTRAP_LOWERING_SOURCE_FINGERPRINT:-}" ]]; then
    printf '%s' "${BOOTSTRAP_LOWERING_SOURCE_FINGERPRINT}"
    return 0
  fi
  local fp=""
  fp="$("${PHP_BIN:-php}" "${root}/script/bootstrap-lowering-source-fingerprint.php" 2>/dev/null | tr -d '\n')" || return 1
  [[ -n "${fp}" ]] || return 1
  export BOOTSTRAP_LOWERING_SOURCE_FINGERPRINT="${fp}"
  printf '%s' "${fp}"
}

bootstrap_lowering_source_stamp_matches() {
  local stamp_path=$1
  local want have=""
  want="$(bootstrap_lowering_source_fingerprint)" || return 1
  if [[ -f "${stamp_path}" ]]; then
    have="$(tr -d '\n' <"${stamp_path}")"
  fi
  [[ "${want}" == "${have}" ]]
}

# True when reuse of a compiled driver/sidecar must be refused (lib/ext drift).
bootstrap_lowering_source_reuse_stale() {
  local stamp_path=$1
  if bootstrap_lowering_source_stamp_matches "${stamp_path}"; then
    return 1
  fi
  if [[ "${BOOTSTRAP_ALLOW_STALE_COMPILED_DRIVER:-${BOOTSTRAP_ALLOW_STALE_SIDECAR:-0}}" == "1" ]]; then
    local want have=""
    want="$(bootstrap_lowering_source_fingerprint)" || return 1
    have=""
    if [[ -f "${stamp_path}" ]]; then
      have="$(tr -d '\n' <"${stamp_path}")"
    fi
    echo "bootstrap-lowering-freshness: using stale compiled artifact (want ${want}, have ${have:-<none>}; BOOTSTRAP_ALLOW_STALE_COMPILED_DRIVER=1 — #21855)" >&2
    return 1
  fi
  return 0
}

bootstrap_lowering_source_refuse_stale_reuse() {
  local stamp_path=$1
  local label=${2:-compiled bootstrap artifact}
  if ! bootstrap_lowering_source_reuse_stale "${stamp_path}"; then
    return 0
  fi
  local want have=""
  want="$(bootstrap_lowering_source_fingerprint)" || return 1
  if [[ -f "${stamp_path}" ]]; then
    have="$(tr -d '\n' <"${stamp_path}")"
  fi
  echo "bootstrap-lowering-freshness: stale ${label} (want ${want}, have ${have:-<none>}) — refusing reuse (#21855)" >&2
  echo "bootstrap-lowering-freshness: refresh gen-0/prelinked sidecars or use Zend gen-0 (BOOTSTRAP_GEN0_ZEND_ONLY=1); opt-in stale: BOOTSTRAP_ALLOW_STALE_COMPILED_DRIVER=1" >&2
  return 1
}

bootstrap_lowering_source_write_build_stamp() {
  local root="${ROOT:-}"
  if [[ -z "${root}" ]]; then
    return 1
  fi
  local fp stamp
  fp="$(bootstrap_lowering_source_fingerprint)" || return 1
  stamp="$(bootstrap_lowering_source_build_stamp)"
  mkdir -p "$(dirname "${stamp}")"
  printf '%s' "${fp}" >"${stamp}"
}

bootstrap_lowering_source_seed_build_stamp_from_prelinked() {
  local root="${ROOT:-}"
  if [[ -z "${root}" ]]; then
    return 1
  fi
  local prelinked build
  prelinked="$(bootstrap_lowering_source_prelinked_stamp)"
  build="$(bootstrap_lowering_source_build_stamp)"
  if [[ -f "${prelinked}" ]]; then
    mkdir -p "$(dirname "${build}")"
    cp -f "${prelinked}" "${build}"
  fi
}

bootstrap_native_compile_driver_lowering_fresh() {
  local driver=$1
  local root="${ROOT:-}"
  if [[ -z "${root}" || ! -x "${driver}" ]]; then
    return 1
  fi
  local seed="${root}/prelinked/bootstrap-gen0/bin-compile-aot"
  if [[ "${driver}" != "${root}/build/bin-compile-aot" \
    && "${driver}" != "${root}/build/bin-compile-aot-inventory" ]]; then
    return 0
  fi
  local stamp
  if [[ -f "${seed}" ]] && cmp -s "${driver}" "${seed}" 2>/dev/null; then
    stamp="$(bootstrap_lowering_source_prelinked_stamp)"
  else
    stamp="$(bootstrap_lowering_source_build_stamp)"
  fi
  if bootstrap_lowering_source_refuse_stale_reuse "${stamp}" "native compile driver ${driver}"; then
    return 0
  fi
  return 1
}
