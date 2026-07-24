#!/usr/bin/env bash
# Refresh committed prelinked/bootstrap-gen0/ after spine entry edits (#8704, #8559).
#
# Wraps: full spine link → copy build/.m3_* sidecars → manifest sha/size refresh → sync check.
#
# Usage:
#   ./script/bootstrap-refresh-gen0-sidecar.sh
#   make bootstrap-gen0-refresh-sidecar
#   BOOTSTRAP_VM_DRIVER_EXECUTE_PROBE_FULL_LINK=1 ./script/bootstrap-refresh-gen0-sidecar.sh
#
# Requires LLVM 9. Expect several minutes for the spine link step.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "${ROOT}"

SKIP_LINK=0
for arg in "$@"; do
  case "${arg}" in
    --skip-link) SKIP_LINK=1 ;;
    -h|--help)
      cat <<'EOF'
Usage: script/bootstrap-refresh-gen0-sidecar.sh [--skip-link]

After test/selfhost/compiler_lib_spine_smoke/main.php changes:
  1. Full spine link (Zend honest compile when lowering fingerprint is stale — #22642;
     otherwise BOOTSTRAP_VM_DRIVER_EXECUTE_PROBE_FULL_LINK=1 native path)
  2. Copy build/.m3_* sidecars + compiler_lib_aot_blob into prelinked/bootstrap-gen0/
  3. Refresh prelinked/bootstrap-gen0/manifest.json size/sha fields
  4. Run check-bootstrap-gen0-manifest-sync.php

Options:
  --skip-link  Copy sidecars + refresh manifest only (build/ already linked).
               Refuses when build/.bootstrap_lowering_source.sha is stale (#21905).

After a verified-fresh copy, stamps lowering_source_fingerprint into
prelinked/bootstrap-gen0/manifest.json (never via size/sha-only refresh).
Refuses fingerprint restamp when compiler_lib blob bytes did not move (#22642).

See docs/bootstrap-m5-fast-path.md and GETTING-STARTED §7b (#8704, #21905).

On Runforge harness hosts, long Zend full-spine refreshes must run in a Docker
container whose *name* matches HARNESS_SPAWNED_CLEANUP_PROTECT_NAMES (e.g.
contains agent-harness) or the harness kills the job at 30 minutes (#22642).
Use: ./script/bootstrap-gen0-refresh-exclusive-docker.sh
EOF
      exit 0
      ;;
    *)
      echo "bootstrap-refresh-gen0-sidecar: unknown argument: ${arg}" >&2
      exit 1
      ;;
  esac
done

# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
# shellcheck source=selfhost-preflight.sh
source "$(dirname "$0")/selfhost-preflight.sh"
# shellcheck source=bootstrap-gen0-install-prelinked-driver.sh
source "$(dirname "$0")/bootstrap-gen0-install-prelinked-driver.sh"
# shellcheck source=bootstrap-lowering-freshness.sh
source "$(dirname "$0")/bootstrap-lowering-freshness.sh"

ci_apply_llvm_memory_env

PRELINKED="${ROOT}/prelinked/bootstrap-gen0"
SPINE_ENTRY="${ROOT}/test/selfhost/compiler_lib_spine_smoke/main.php"
SPINE_OUT="${ROOT}/build/selfhost-lib-spine-smoke"
LIB_BLOB="${ROOT}/build/.m3_compiler_lib_aot_blob"
STAMP="${ROOT}/build/.m3_compiler_lib_sidecar.sha"

old_lib_sha=""
if [[ -f "${PRELINKED}/compiler_lib_aot_blob" ]]; then
  old_lib_sha="$(sha256sum "${PRELINKED}/compiler_lib_aot_blob" | awk '{print $1}')"
fi
old_fp=""
if [[ -f "${PRELINKED}/manifest.json" ]]; then
  old_fp="$(php -r '
    $m = json_decode((string) file_get_contents($argv[1]), true);
    echo is_array($m) ? strtolower(trim((string) ($m["lowering_source_fingerprint"] ?? ""))) : "";
  ' "${PRELINKED}/manifest.json" 2>/dev/null || true)"
fi
live_fp="$(bootstrap_lowering_source_fingerprint)" || live_fp=""

if [[ "${SKIP_LINK}" -eq 0 ]]; then
  if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
    echo "bootstrap-refresh-gen0-sidecar: LLVM 9 not found (install via script/install-llvm9.sh)" >&2
    exit 2
  fi

  if [[ -n "${live_fp}" && -n "${old_fp}" && "${live_fp}" != "${old_fp}" ]] \
    || [[ "${BOOTSTRAP_GEN0_FORCE_ZEND_SPINE:-0}" == "1" ]]; then
    echo "==> Zend honest full-spine compile (lowering fingerprint stale or BOOTSTRAP_GEN0_FORCE_ZEND_SPINE=1 — #22642)"
    # Fail-fast nikic parse of every spine PHP file before multi-hour AOT (#22642 r9:
    # DateTime*/ in a docblock aborted parseAndCompile after ~226m).
    if ! php "${ROOT}/script/bootstrap-spine-nikic-preflight.php"; then
      echo "bootstrap-refresh-gen0-sidecar: spine nikic preflight failed — fix syntax before Zend AOT" >&2
      exit 1
    fi
    # Default CI ulimit -v is 8 GiB (#436). Zend full-spine AOT grows virtual size (mmap/LLVM)
    # far ahead of RSS — a finite -v cap SIGKILLs (exit 137) around ~1 GiB RSS while still in
    # parseAndCompileFile (#22642). Disable virtual-memory ulimit here; Docker cgroup + PHP
    # memory_limit remain the real budgets.
    export PHP_COMPILER_CI_RAM_GB=0
    if command -v ci_apply_resource_limits >/dev/null 2>&1; then
      ci_apply_resource_limits || true
    fi
    ulimit -v unlimited 2>/dev/null || ulimit -v 0 2>/dev/null || true
    echo "bootstrap-refresh-gen0-sidecar: ulimit -v=$(ulimit -v) for Zend spine (#22642)"
    # Prefer at least 16G PHP heap for TypeReconstructor on the full spine.
    if [[ -z "${PHP_COMPILER_MEMORY_LIMIT:-}" ]] || [[ "${PHP_COMPILER_MEMORY_LIMIT}" == "1536M" ]] \
      || [[ "${PHP_COMPILER_MEMORY_LIMIT}" == "8192M" ]]; then
      export PHP_COMPILER_MEMORY_LIMIT=16384M
    fi
    mkdir -p "${ROOT}/build"
    rm -f "${SPINE_OUT}" "${LIB_BLOB}"
    if ! bootstrap_compiler_lib_honest_zend_compile "${SPINE_OUT}" "${SPINE_ENTRY}" full; then
      echo "bootstrap-refresh-gen0-sidecar: Zend honest spine compile failed" >&2
      exit 1
    fi
    if [[ ! -x "${SPINE_OUT}" ]]; then
      echo "bootstrap-refresh-gen0-sidecar: Zend spine compile produced no executable ${SPINE_OUT}" >&2
      exit 1
    fi
    cp -f "${SPINE_OUT}" "${LIB_BLOB}"
    chmod +x "${LIB_BLOB}"
    want_sha="$(bootstrap_compiler_lib_spine_entry_sha)" || true
    if [[ -n "${want_sha:-}" ]]; then
      printf '%s' "${want_sha}" >"${STAMP}"
    fi
  else
    export BOOTSTRAP_ALLOW_STALE_COMPILED_DRIVER=1
    export BOOTSTRAP_ALLOW_STALE_SIDECAR=1
    echo "==> M2 full spine link (BOOTSTRAP_VM_DRIVER_EXECUTE_PROBE_FULL_LINK=1; stale gen-0 seed allowed for refresh)"
    BOOTSTRAP_VM_DRIVER_EXECUTE_PROBE_FULL_LINK=1 \
      bash "${ROOT}/script/bootstrap-selfhost-lib-spine-smoke-link.sh"
    unset BOOTSTRAP_ALLOW_STALE_COMPILED_DRIVER BOOTSTRAP_ALLOW_STALE_SIDECAR
  fi
  bootstrap_lowering_source_write_build_stamp
else
  bootstrap_lowering_source_refuse_stale_reuse \
    "$(bootstrap_lowering_source_build_stamp)" \
    "build/ artifact for gen-0 sidecar refresh" \
    || {
      echo "bootstrap-refresh-gen0-sidecar: refusing refresh from stale build/ (rebuild first, or omit --skip-link)" >&2
      exit 1
    }
fi

if [[ ! -x "${SPINE_OUT}" ]]; then
  echo "bootstrap-refresh-gen0-sidecar: missing ${SPINE_OUT} (run link first)" >&2
  exit 1
fi
if [[ ! -f "${LIB_BLOB}" || ! -s "${LIB_BLOB}" ]]; then
  echo "bootstrap-refresh-gen0-sidecar: missing ${LIB_BLOB} after spine link" >&2
  exit 1
fi
if [[ ! -f "${STAMP}" ]]; then
  echo "bootstrap-refresh-gen0-sidecar: missing ${STAMP} after spine link" >&2
  exit 1
fi

mkdir -p "${PRELINKED}"

echo "==> copy spine sidecars build/ → prelinked/bootstrap-gen0/"
cp -f "${LIB_BLOB}" "${PRELINKED}/compiler_lib_aot_blob"
cp -f "${LIB_BLOB}" "${PRELINKED}/.m3_compiler_lib_aot_blob"
chmod +x "${PRELINKED}/compiler_lib_aot_blob" "${PRELINKED}/.m3_compiler_lib_aot_blob"
cp -f "${STAMP}" "${PRELINKED}/.m3_compiler_lib_sidecar.sha"

copied=0
for sidecar in "${ROOT}"/build/.m3_*; do
  [[ -f "${sidecar}" && -s "${sidecar}" ]] || continue
  base="$(basename "${sidecar}")"
  cp -f "${sidecar}" "${PRELINKED}/${base}"
  chmod +x "${PRELINKED}/${base}" 2>/dev/null || true
  copied=$((copied + 1))
done

if [[ -f "${ROOT}/build/bin-compile-aot" && -x "${ROOT}/build/bin-compile-aot" ]]; then
  driver_bytes="$(wc -c <"${ROOT}/build/bin-compile-aot")"
  manifest_min="$(php -r '
    require "script/bootstrap-gen0-manifest-lib.php";
    echo bootstrap_gen0_manifest_driver_min_bytes($argv[1]);
  ' "${ROOT}" 2>/dev/null || echo 0)"
  if [[ "${driver_bytes}" =~ ^[0-9]+$ && "${manifest_min}" =~ ^[0-9]+$ ]] \
    && (( driver_bytes >= manifest_min )); then
    cp -f "${ROOT}/build/bin-compile-aot" "${PRELINKED}/bin-compile-aot"
    cp -f "${ROOT}/build/bin-compile-aot" "${PRELINKED}/.m3_bin_compile_aot_blob"
    chmod +x "${PRELINKED}/bin-compile-aot" "${PRELINKED}/.m3_bin_compile_aot_blob"
    echo "bootstrap-refresh-gen0-sidecar: refreshed bin-compile-aot (${driver_bytes} bytes)"
  else
    echo "bootstrap-refresh-gen0-sidecar: skip bin-compile-aot refresh (${driver_bytes} bytes < manifest min ${manifest_min})" >&2
  fi
fi

if [[ -f "${ROOT}/build/.m3_compiler_minimal_aot_blob" ]]; then
  cp -f "${ROOT}/build/.m3_compiler_minimal_aot_blob" "${PRELINKED}/compiler_minimal_aot_blob"
  cp -f "${ROOT}/build/.m3_compiler_minimal_aot_blob" "${PRELINKED}/.m3_compiler_minimal_aot_blob"
  chmod +x "${PRELINKED}/compiler_minimal_aot_blob" "${PRELINKED}/.m3_compiler_minimal_aot_blob"
fi

echo "bootstrap-refresh-gen0-sidecar: copied ${copied} build/.m3_* blobs + compiler_lib sidecar"

new_lib_sha="$(sha256sum "${PRELINKED}/compiler_lib_aot_blob" | awk '{print $1}')"
if [[ -n "${live_fp}" && -n "${old_fp}" && "${live_fp}" != "${old_fp}" ]]; then
  if [[ -n "${old_lib_sha}" && "${new_lib_sha}" == "${old_lib_sha}" ]]; then
    echo "bootstrap-refresh-gen0-sidecar: refusing restamp-only fingerprint update — compiler_lib blob sha unchanged (${new_lib_sha})" >&2
    echo "bootstrap-refresh-gen0-sidecar: need an honest spine rebuild (Zend path ran but produced identical bytes, or sidecar fallback copied the seed — #22642)" >&2
    exit 1
  fi
fi

echo "==> refresh prelinked/bootstrap-gen0/manifest.json (size/sha only — no provenance stamp)"
php "${ROOT}/script/bootstrap-gen0-manifest-refresh.php"

echo "==> stamp lowering_source_fingerprint (verified-fresh copy only — #21905)"
php -r '
require $argv[1]."/script/bootstrap-gen0-manifest-lib.php";
$m = bootstrap_gen0_manifest_stamp_lowering_fingerprint($argv[1]);
fwrite(STDOUT, "bootstrap-refresh-gen0-sidecar: stamped lowering_source_fingerprint="
    .substr((string) ($m["lowering_source_fingerprint"] ?? ""), 0, 16)."…\n");
' "${ROOT}"

echo "==> verify gen-0 manifest sync"
php "${ROOT}/script/check-bootstrap-gen0-manifest-sync.php"

echo "bootstrap-refresh-gen0-sidecar: OK — commit prelinked/bootstrap-gen0/ when intentional (#8704, #21905)"
