#!/usr/bin/env bash
# M2 VM driver execute smoke: spine-linked binary runs bin/vm.php run() on -r fixture (#2201).
#
# Fast feedback loop: run the native PHP_COMPILER_VM_DRIVER_EXECUTE gate on an existing
# build/selfhost-lib-spine-smoke (~20ms). Full spine relink is opt-in via
# BOOTSTRAP_VM_DRIVER_EXECUTE_PROBE_FULL_LINK=1 (north-star5 / post-spine-entry edits).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENTRY="${ROOT}/test/selfhost/compiler_lib_spine_smoke/main.php"
OUT="${ROOT}/build/selfhost-lib-spine-smoke"
LINK="${ROOT}/script/bootstrap-selfhost-lib-spine-smoke-link.sh"

bootstrap_vm_driver_execute_probe_llvm_env() {
  local llvm=""
  if [[ -n "${PHP_COMPILER_LLVM_PATH:-}" && -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
    llvm="${PHP_COMPILER_LLVM_PATH}"
  elif [[ -f "${ROOT}/.llvm/libLLVM-9.so.1" ]]; then
    llvm="${ROOT}/.llvm"
  elif [[ -f /opt/llvm9/libLLVM-9.so.1 ]]; then
    llvm=/opt/llvm9
  fi
  if [[ -z "${llvm}" ]]; then
    return 1
  fi
  export PHP_COMPILER_LLVM_PATH="${llvm}"
  export LD_LIBRARY_PATH="${llvm}${LD_LIBRARY_PATH:+:${LD_LIBRARY_PATH}}"
  return 0
}

if ! bootstrap_vm_driver_execute_probe_llvm_env; then
  echo "bootstrap-selfhost-vm-driver-execute-probe: LLVM 9 not found (skip)" >&2
  exit 2
fi

want_sha="$(sha1sum "${ENTRY}" | awk '{print $1}')"
stamp="${ROOT}/build/.m3_compiler_lib_sidecar.sha"
have_sha=""
if [[ -f "${stamp}" ]]; then
  have_sha="$(tr -d '\n' <"${stamp}")"
fi

bootstrap_vm_driver_execute_probe_run() {
  env PHP_COMPILER_VM_DRIVER_EXECUTE=1 "${OUT}" 2>&1
}

bootstrap_vm_driver_execute_probe_passes() {
  local probe_out=$1
  local probe_code=$2
  [[ "${probe_code}" -eq 0 ]] && grep -q 'vm driver ok' <<< "${probe_out}"
}

# --- Fast path: native env probe only (no compile/link, no php-env vendor patches) ---
fast_probe_failed=0
if [[ -x "${OUT}" ]]; then
  set +e
  probe_out="$(bootstrap_vm_driver_execute_probe_run)"
  probe_code=$?
  set -e
  if bootstrap_vm_driver_execute_probe_passes "${probe_out}" "${probe_code}"; then
    echo "bootstrap-selfhost-vm-driver-execute-probe: OK ${OUT}"
    exit 0
  fi
  fast_probe_failed=1
fi

# shellcheck source=bootstrap-gen0-install-prelinked-driver.sh
source "$(dirname "$0")/bootstrap-gen0-install-prelinked-driver.sh"

full_link="${BOOTSTRAP_VM_DRIVER_EXECUTE_PROBE_FULL_LINK:-0}"
if [[ "${full_link}" != "1" && "${want_sha}" != "${have_sha}" ]]; then
  echo "bootstrap-selfhost-vm-driver-execute-probe: sidecar stamp stale (want ${want_sha}, have ${have_sha:-<none>}) — fast path skips relink (set BOOTSTRAP_VM_DRIVER_EXECUTE_PROBE_FULL_LINK=1 to rebuild)" >&2
fi

relink=0
if [[ ! -x "${OUT}" ]]; then
  relink=1
elif [[ "${fast_probe_failed}" == "1" ]]; then
  echo "bootstrap-selfhost-vm-driver-execute-probe: fast path probe failed — seeding from prelinked gen-0 or relinking" >&2
  relink=1
elif [[ "${ENTRY}" -nt "${OUT}" ]]; then
  relink=1
elif [[ "${full_link}" == "1" && "${want_sha}" != "${have_sha}" ]]; then
  echo "bootstrap-selfhost-vm-driver-execute-probe: stale compiler_lib sidecar (want ${want_sha}, have ${have_sha:-<none>})" >&2
  relink=1
fi

if [[ "${relink}" == "1" ]]; then
  if [[ "${full_link}" != "1" ]] \
    && bootstrap_copy_prelinked_compiler_lib_spine_blob "${OUT}"; then
    echo "bootstrap-selfhost-vm-driver-execute-probe: seeded ${OUT} from prelinked/bootstrap-gen0 (fast)" >&2
    set +e
    probe_out="$(bootstrap_vm_driver_execute_probe_run)"
    probe_code=$?
    set -e
    if bootstrap_vm_driver_execute_probe_passes "${probe_out}" "${probe_code}"; then
      echo "bootstrap-selfhost-vm-driver-execute-probe: OK ${OUT}"
      exit 0
    fi
    echo "bootstrap-selfhost-vm-driver-execute-probe: prelinked seed failed VM probe" >&2
    printf '%s\n' "${probe_out}" >&2
    rm -f "${OUT}"
    # north-star5-verify-fast must stay ~minutes (#10533). Accidental Zend full-spine
    # fallback here is multi-hour and poisons shared bind-mounts — require an explicit
    # FULL_LINK=1 (or make bootstrap-gen0-refresh-sidecar / exclusive docker refresh).
    echo "bootstrap-selfhost-vm-driver-execute-probe: refusing multi-hour Zend spine fallback without BOOTSTRAP_VM_DRIVER_EXECUTE_PROBE_FULL_LINK=1 (refresh prelinked/bootstrap-gen0 compiler_lib via script/bootstrap-refresh-gen0-sidecar.sh — #8559, #10533)" >&2
    exit 1
  elif [[ "${full_link}" != "1" ]]; then
    # No usable prelinked blob and not an explicit full-link request: fail fast.
    echo "bootstrap-selfhost-vm-driver-execute-probe: no working spine binary/prelinked seed; refusing multi-hour Zend fallback without BOOTSTRAP_VM_DRIVER_EXECUTE_PROBE_FULL_LINK=1 (#10533)" >&2
    exit 1
  fi
  # shellcheck source=php-env.sh
  source "$(dirname "$0")/php-env.sh"
  ci_apply_llvm_memory_env
  if [[ "${full_link}" == "1" && "${want_sha}" != "${have_sha}" ]]; then
    BOOTSTRAP_FORCE_COMPILER_LIB_SIDECAR_REGEN=1 bootstrap_ensure_m3_compiler_lib_sidecar 2>/dev/null || true
  fi
  "${LINK}"
fi

test -x "${OUT}"
if [[ -f "${stamp}" ]]; then
  have_sha="$(tr -d '\n' <"${stamp}")"
fi
set +e
probe_out="$(bootstrap_vm_driver_execute_probe_run)"
probe_code=$?
set -e
if bootstrap_vm_driver_execute_probe_passes "${probe_out}" "${probe_code}"; then
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
echo "bootstrap-selfhost-vm-driver-execute-probe: unexpected stdout (want vm driver ok)" >&2
printf '%s\n' "${probe_out}" >&2
exit 1
