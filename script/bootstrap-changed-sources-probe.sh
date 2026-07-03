#!/usr/bin/env bash
# Gen-N changed-sources probe (#15598): gen-2 must compile working-tree edits into gen-3,
# not copy stale prelinked/bootstrap-gen0/ bytes (#8710).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "${ROOT}"

# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
# shellcheck source=bootstrap-resolve-compile-invoke.sh
source "$(dirname "$0")/bootstrap-resolve-compile-invoke.sh"
# shellcheck source=bootstrap-gen0-install-prelinked-driver.sh
source "$(dirname "$0")/bootstrap-gen0-install-prelinked-driver.sh"
# shellcheck source=bootstrap-honest-compile-lib.sh
source "$(dirname "$0")/bootstrap-honest-compile-lib.sh"
ci_apply_llvm_memory_env
ci_ensure_vendor_patches

SOURCE="${ROOT}/examples/000-HelloWorld/example.php"
LIB_TWEAK="${ROOT}/lib/OpCode.php"
DRIVER="${ROOT}/build/bin-compile-aot"
GEN3_V1="${ROOT}/build/bootstrap-changed-sources-gen3-v1"
GEN3_V2="${ROOT}/build/bootstrap-changed-sources-gen3-v2"
MARKER_V1='changed_sources_marker_v1'
MARKER_V2='changed_sources_marker_v2'
PROBE_ID="probe_$$"
SOURCE_BAK=""
LIB_BAK=""

cleanup() {
  if [[ -n "${SOURCE_BAK}" && -f "${SOURCE_BAK}" ]]; then
    mv -f "${SOURCE_BAK}" "${SOURCE}"
  fi
  if [[ -n "${LIB_BAK}" && -f "${LIB_BAK}" ]]; then
    mv -f "${LIB_BAK}" "${LIB_TWEAK}"
  fi
}
trap cleanup EXIT

usage() {
  cat <<EOF
Usage: script/bootstrap-changed-sources-probe.sh

Gen-N changed-sources probe (#15598):
  1. gen-2 compiles ${SOURCE} -> gen-3 (marker v1)
  2. patch HelloWorld example + lib/OpCode.php in the working tree
  3. gen-2 recompiles -> gen-3' must differ and run marker v2
  4. fail when gen-3 matches stale prelinked/bootstrap-gen0/ (#8710)

Exit codes:
  0  probe OK
  1  hard failure (missing files, compile/run mismatch, stale prelinked emit)
  2  LLVM 9 not found (skip)
EOF
}

for arg in "$@"; do
  case "${arg}" in
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "bootstrap-changed-sources-probe: unknown argument: ${arg}" >&2
      usage >&2
      exit 1
      ;;
  esac
done

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-changed-sources-probe: LLVM 9 not found (skip)" >&2
  exit 2
fi

for f in "${SOURCE}" "${LIB_TWEAK}"; do
  if [[ ! -f "${f}" ]]; then
    echo "bootstrap-changed-sources-probe: missing ${f}" >&2
    exit 1
  fi
done

mkdir -p "${ROOT}/build"

PRELINKED_GEN0="${ROOT}/prelinked/bootstrap-gen0/bin-compile-aot"
INVENTORY_DRIVER="${ROOT}/build/bin-compile-aot-inventory"
export PHP_COMPILER_SELFHOST_AOT=1
if [[ ! -x "${DRIVER}" ]]; then
  if [[ -x "${INVENTORY_DRIVER}" ]]; then
    cp -f "${INVENTORY_DRIVER}" "${DRIVER}"
    chmod +x "${DRIVER}"
  elif [[ -x "${PRELINKED_GEN0}" ]]; then
    cp -f "${PRELINKED_GEN0}" "${DRIVER}"
    chmod +x "${DRIVER}"
  else
    bootstrap_gen0_install_prelinked_driver 2>/dev/null || true
    if [[ -x "${PRELINKED_GEN0}" ]]; then
      cp -f "${PRELINKED_GEN0}" "${DRIVER}"
      chmod +x "${DRIVER}"
    fi
  fi
fi
if [[ ! -x "${DRIVER}" ]]; then
  echo "bootstrap-changed-sources-probe: no gen-2 driver under build/ (run make bootstrap-selfhost-link first)" >&2
  exit 1
fi

bootstrap_changed_sources_apply_marker() {
  local marker=$1
  SOURCE_BAK="$(mktemp)"
  LIB_BAK="$(mktemp)"
  cp -f "${SOURCE}" "${SOURCE_BAK}"
  cp -f "${LIB_TWEAK}" "${LIB_BAK}"
  sed -i "s/Hello World/${marker}/" "${SOURCE}"
  printf '\n// bootstrap-changed-sources-probe:%s:%s\n' "${PROBE_ID}" "${marker}" >>"${LIB_TWEAK}"
}

bootstrap_changed_sources_sidecar_for_source() {
  rm -f \
    "${ROOT}/build/.m3_helloworld_aot_blob" \
    "${ROOT}/build/.m3_compile_smoke_aot_blob"
}

bootstrap_changed_sources_compile() {
  local out=$1
  local want_marker="${2:-}"
  bootstrap_changed_sources_sidecar_for_source
  rm -f "${out}"
  set +e
  local log code
  BOOTSTRAP_ALLOW_SIDECAR_EMIT_FALLBACK=0
  export BOOTSTRAP_ALLOW_SIDECAR_EMIT_FALLBACK
  log="$(bootstrap_compile_invoke "${out}" "${SOURCE}" env PHP_COMPILER_SELFHOST_AOT=1 2>&1)"
  code=$?
  set -e
  local need_zend=0
  if [[ "${code}" -ne 0 ]] \
    || bootstrap_honest_compile_log_uses_sidecar_recovery "${log}" \
    || grep -qE 'gen-0 sidecar emit fallback' <<< "${log}"; then
    need_zend=1
  elif [[ -n "${want_marker}" && -x "${out}" ]]; then
    local run_out
    run_out="$("${out}" 2>&1 || true)"
    if ! grep -qF "${want_marker}" <<< "${run_out}"; then
      echo "bootstrap-changed-sources-probe: native gen-2 emit missing marker (sidecar masked edit — retry Zend #15598)" >&2
      need_zend=1
    fi
  fi
  if [[ "${need_zend}" == "1" ]]; then
    bootstrap_changed_sources_sidecar_for_source
    rm -f "${out}"
    set +e
    BOOTSTRAP_GEN0_ZEND_ONLY=1
    export BOOTSTRAP_GEN0_ZEND_ONLY
    log="$(bootstrap_compile_invoke "${out}" "${SOURCE}" 2>&1)"
    code=$?
    set -e
    unset BOOTSTRAP_GEN0_ZEND_ONLY
  fi
  printf '%s\n' "${log}"
  if [[ "${code}" -ne 0 ]]; then
    echo "bootstrap-changed-sources-probe: gen-2/Zend compile failed (exit ${code})" >&2
    return 1
  fi
  if [[ ! -x "${out}" ]]; then
    echo "bootstrap-changed-sources-probe: missing gen-3 output ${out}" >&2
    return 1
  fi
  if bootstrap_gen3_emit_matches_stale_prelinked_gen0 "${out}"; then
    if [[ "${BOOTSTRAP_ALLOW_STALE_SIDECAR:-0}" == "1" ]]; then
      echo "bootstrap-changed-sources-probe: gen-3 matches stale prelinked (BOOTSTRAP_ALLOW_STALE_SIDECAR=1 — #8703)" >&2
    else
      echo "bootstrap-changed-sources-probe: gen-3 matches stale prelinked/bootstrap-gen0/ (sidecar copy — refresh gen-0 or rebuild inventory argv driver #8710)" >&2
      return 1
    fi
  fi
  return 0
}

bootstrap_changed_sources_run_marker() {
  local gen3=$1
  local want=$2
  local out
  out="$("${gen3}" 2>&1 || true)"
  if ! grep -qF "${want}" <<< "${out}"; then
    echo "bootstrap-changed-sources-probe: gen-3 ${gen3} missing marker ${want}" >&2
    printf '%s\n' "${out}" >&2
    return 1
  fi
  return 0
}

echo "==> bootstrap-changed-sources-probe: gen-2 compile baseline (marker v1)"
bootstrap_changed_sources_apply_marker "${MARKER_V1}"
bootstrap_changed_sources_compile "${GEN3_V1}" "${MARKER_V1}"
bootstrap_changed_sources_run_marker "${GEN3_V1}" "${MARKER_V1}"

mv -f "${SOURCE_BAK}" "${SOURCE}"
mv -f "${LIB_BAK}" "${LIB_TWEAK}"
SOURCE_BAK=""
LIB_BAK=""

echo "==> bootstrap-changed-sources-probe: gen-2 recompile after lib/ + entry edits"
bootstrap_changed_sources_apply_marker "${MARKER_V2}"
bootstrap_changed_sources_compile "${GEN3_V2}" "${MARKER_V2}"
bootstrap_changed_sources_run_marker "${GEN3_V2}" "${MARKER_V2}"

if cmp -s "${GEN3_V1}" "${GEN3_V2}"; then
  echo "bootstrap-changed-sources-probe: gen-3 hash unchanged after working-tree edits (want distinct emit #15598)" >&2
  exit 1
fi

GEN2="${BOOTSTRAP_COMPILE_DRIVER:-${DRIVER}}"
echo "bootstrap-changed-sources-probe: OK gen-2=${GEN2} gen-3-v1=${GEN3_V1} gen-3-v2=${GEN3_V2} (changed sources #15598, stale guard #8710)"
