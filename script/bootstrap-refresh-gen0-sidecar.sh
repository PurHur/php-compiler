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
  1. Full native spine link (BOOTSTRAP_VM_DRIVER_EXECUTE_PROBE_FULL_LINK=1)
  2. Copy build/.m3_* sidecars + compiler_lib_aot_blob into prelinked/bootstrap-gen0/
  3. Refresh prelinked/bootstrap-gen0/manifest.json size/sha fields
  4. Run check-bootstrap-gen0-manifest-sync.php

Options:
  --skip-link  Copy sidecars + refresh manifest only (build/ already linked).
               Refuses when build/.bootstrap_lowering_source.sha is stale (#21905).

After a verified-fresh copy, stamps lowering_source_fingerprint into
prelinked/bootstrap-gen0/manifest.json (never via size/sha-only refresh).

See docs/bootstrap-m5-fast-path.md and GETTING-STARTED §7b (#8704, #21905).
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

if [[ "${SKIP_LINK}" -eq 0 ]]; then
  if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
    echo "bootstrap-refresh-gen0-sidecar: LLVM 9 not found (install via script/install-llvm9.sh)" >&2
    exit 2
  fi
  # Cold-tree deadlock (#22642): refreshing gen-0 needs a compile driver, but the committed
  # seed is refused once it is stale (#21855) and BOOTSTRAP_NO_ZEND_FALLBACK=1 forbids Zend —
  # so the refresh dies after ~8s with "failed to build compiled driver" and the caller has to
  # rediscover BOOTSTRAP_GEN0_ZEND_ONLY=1 from a log line. On a tree with no usable driver,
  # bootstrapping through Zend is not a compromise, it is what gen-0 means. Select it here.
  if [[ -z "${BOOTSTRAP_GEN0_ZEND_ONLY:-}" ]]; then
    if [[ ! -x "${ROOT}/build/bin-compile-aot" ]] \
      || ! bootstrap_lowering_source_stamp_matches "$(bootstrap_lowering_source_build_stamp)"; then
      echo "==> no compile driver in this tree matches current lowering sources — bootstrapping via Zend"
      echo "    (BOOTSTRAP_GEN0_ZEND_ONLY=1 selected automatically; export BOOTSTRAP_GEN0_ZEND_ONLY=0 to refuse and fail instead)"
      export BOOTSTRAP_GEN0_ZEND_ONLY=1
    fi
  fi

  echo "==> M2 full spine link (BOOTSTRAP_VM_DRIVER_EXECUTE_PROBE_FULL_LINK=1)"
  BOOTSTRAP_VM_DRIVER_EXECUTE_PROBE_FULL_LINK=1 \
    bash "${ROOT}/script/bootstrap-selfhost-lib-spine-smoke-link.sh"
  # Honest build stamp: spine link just ran against current lib/ext/patches (#21905).
  bootstrap_lowering_source_write_build_stamp
else
  # --skip-link: refuse to copy/stamp from a stale build/ artifact (#21905 / #21855).
  bootstrap_lowering_source_refuse_stale_reuse \
    "$(bootstrap_lowering_source_build_stamp)" \
    "build/ artifact for gen-0 sidecar refresh" \
    || {
      echo "bootstrap-refresh-gen0-sidecar: refusing refresh from stale build/ (rebuild first, or omit --skip-link)" >&2
      exit 1
    }
fi

SPINE_OUT="${ROOT}/build/selfhost-lib-spine-smoke"
LIB_BLOB="${ROOT}/build/.m3_compiler_lib_aot_blob"
STAMP="${ROOT}/build/.m3_compiler_lib_sidecar.sha"

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

PRELINKED="${ROOT}/prelinked/bootstrap-gen0"
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
