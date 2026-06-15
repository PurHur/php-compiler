#!/usr/bin/env bash
# M2 VM driver execute smoke: spine-linked binary runs bin/vm.php run() on -r fixture (#2201).
# --prelinked-only: run committed prelinked/bootstrap-gen0/compiler_lib_aot_blob (no relink; #8683).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENTRY="${ROOT}/test/selfhost/compiler_lib_spine_smoke/main.php"
OUT="${ROOT}/build/selfhost-lib-spine-smoke"
PRELINKED_ONLY=0
LINK="${ROOT}/script/bootstrap-selfhost-lib-spine-smoke-link.sh"
# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
# shellcheck source=bootstrap-gen0-install-prelinked-driver.sh
source "$(dirname "$0")/bootstrap-gen0-install-prelinked-driver.sh"
ci_apply_llvm_memory_env

while [[ $# -gt 0 ]]; do
  case "$1" in
    --prelinked-only) PRELINKED_ONLY=1; shift ;;
    -h|--help)
      cat <<'EOF'
Usage: script/bootstrap-selfhost-vm-driver-execute-probe.sh [--prelinked-only]

  --prelinked-only  run prelinked/bootstrap-gen0/compiler_lib_aot_blob (no relink; #8683)
EOF
      exit 0
      ;;
    *) echo "bootstrap-selfhost-vm-driver-execute-probe: unknown argument: $1" >&2; exit 1 ;;
  esac
done

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-selfhost-vm-driver-execute-probe: LLVM 9 not found (skip)" >&2
  exit 2
fi

if [[ "${PRELINKED_ONLY}" -eq 1 ]]; then
  OUT="${ROOT}/prelinked/bootstrap-gen0/compiler_lib_aot_blob"
  if [[ ! -x "${OUT}" ]]; then
    echo "bootstrap-selfhost-vm-driver-execute-probe: missing prelinked spine blob ${OUT}" >&2
    exit 1
  fi
  set +e
  probe_out="$(
    env PHP_COMPILER_VM_DRIVER_EXECUTE=1 "${OUT}" 2>&1
  )"
  probe_code=$?
  set -e
  if [[ "${probe_code}" -eq 0 ]] && grep -q 'vm driver ok' <<< "${probe_out}"; then
    echo "bootstrap-selfhost-vm-driver-execute-probe: OK ${OUT} (prelinked-only)"
    exit 0
  fi
  if [[ "${probe_code}" -ne 0 ]]; then
    echo "bootstrap-selfhost-vm-driver-execute-probe: native execute failed (exit ${probe_code})" >&2
    printf '%s\n' "${probe_out}" >&2
    exit 1
  fi
  echo "bootstrap-selfhost-vm-driver-execute-probe: unexpected stdout (want vm driver ok; refresh prelinked/bootstrap-gen0 — #8559)" >&2
  printf '%s\n' "${probe_out}" >&2
  exit 1
fi

want_sha="$(bootstrap_compiler_lib_spine_entry_sha)" || {
  echo "bootstrap-selfhost-vm-driver-execute-probe: missing spine entry ${ENTRY}" >&2
  exit 1
}
stamp="${ROOT}/build/.m3_compiler_lib_sidecar.sha"
have_sha=""
if [[ -f "${stamp}" ]]; then
  have_sha="$(tr -d '\n' <"${stamp}")"
fi

relink=0
if [[ ! -x "${OUT}" ]]; then
  relink=1
elif [[ "${ENTRY}" -nt "${OUT}" ]]; then
  relink=1
elif [[ "${want_sha}" != "${have_sha}" ]]; then
  echo "bootstrap-selfhost-vm-driver-execute-probe: stale compiler_lib sidecar (want ${want_sha}, have ${have_sha:-<none>})" >&2
  relink=1
fi

if [[ "${relink}" == "1" ]]; then
  if [[ "${want_sha}" != "${have_sha}" ]]; then
    BOOTSTRAP_FORCE_COMPILER_LIB_SIDECAR_REGEN=1 bootstrap_ensure_m3_compiler_lib_sidecar 2>/dev/null || true
  fi
  "${LINK}"
fi

test -x "${OUT}"
if [[ -f "${stamp}" ]]; then
  have_sha="$(tr -d '\n' <"${stamp}")"
fi
set +e
probe_out="$(
  env PHP_COMPILER_VM_DRIVER_EXECUTE=1 "${OUT}" 2>&1
)"
probe_code=$?
set -e
if [[ "${probe_code}" -eq 0 ]] && grep -q 'vm driver ok' <<< "${probe_out}"; then
  echo "bootstrap-selfhost-vm-driver-execute-probe: OK ${OUT}"
  exit 0
fi
if [[ "${want_sha}" != "${have_sha}" ]]; then
  echo "bootstrap-selfhost-vm-driver-execute-probe: sidecar stamp stale after link (want ${want_sha}, have ${have_sha:-<none>})" >&2
fi
if [[ "${probe_code}" -ne 0 ]]; then
  echo "bootstrap-selfhost-vm-driver-execute-probe: native execute failed (exit ${probe_code})" >&2
  printf '%s\n' "${probe_out}" >&2
  exit 1
fi
if ! grep -q 'vm driver ok' <<< "${probe_out}"; then
  echo "bootstrap-selfhost-vm-driver-execute-probe: unexpected stdout (want vm driver ok)" >&2
  printf '%s\n' "${probe_out}" >&2
  exit 1
fi
echo "bootstrap-selfhost-vm-driver-execute-probe: OK ${OUT}"
