#!/usr/bin/env bash
# Re-stamp lowering_source_fingerprint when committed gen-0 bytes are current but the
# manifest stamp drifted (e.g. stale BOOTSTRAP_LOWERING_SOURCE_FINGERPRINT env during
# refresh — #36145). Does not rebuild driver bytes; requires functional smoke pass.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "${ROOT}"

# shellcheck source=bootstrap-lowering-freshness.sh
source "$(dirname "$0")/bootstrap-lowering-freshness.sh"

export ROOT
bootstrap_lowering_source_fingerprint_reset_cache

PRELINKED="${ROOT}/prelinked/bootstrap-gen0"
DRIVER="${PRELINKED}/bin-compile-aot"

if [[ ! -x "${DRIVER}" ]]; then
  echo "bootstrap-gen0-sync-lowering-stamp: missing ${DRIVER}" >&2
  exit 1
fi

export BOOTSTRAP_GEN0_DRIVER="${DRIVER}"
if ! bash "${ROOT}/script/bootstrap-gen0-driver-functional-smoke.sh"; then
  echo "bootstrap-gen0-sync-lowering-stamp: refusing stamp — driver failed functional smoke (#23468)" >&2
  exit 1
fi
unset BOOTSTRAP_GEN0_DRIVER

mkdir -p "${ROOT}/build"
cp -f "${DRIVER}" "${ROOT}/build/bin-compile-aot"
chmod +x "${ROOT}/build/bin-compile-aot"
cp -f "${PRELINKED}/.m3_bin_compile_aot_blob" "${ROOT}/build/.m3_bin_compile_aot_blob"
cp -f "${PRELINKED}/compiler_minimal_aot_blob" "${ROOT}/build/.m3_compiler_minimal_aot_blob"
cp -f "${PRELINKED}/compiler_lib_aot_blob" "${ROOT}/build/.m3_compiler_lib_aot_blob"

bootstrap_lowering_source_fingerprint_reset_cache
live="$(bootstrap_lowering_source_fingerprint)"
echo "bootstrap-gen0-sync-lowering-stamp: live lowering_source_fingerprint=${live:0:16}…"

export BOOTSTRAP_GEN0_ARGV_ONLY_RECEIPT=1
php -r '
require $argv[1]."/script/bootstrap-gen0-manifest-lib.php";
$r = bootstrap_gen0_write_build_receipt($argv[1]);
fwrite(STDOUT, "bootstrap-gen0-sync-lowering-stamp: receipt fingerprint="
    .substr((string) $r["lowering_source_fingerprint"], 0, 16)."…\n");
' "${ROOT}"

bootstrap_lowering_source_fingerprint_reset_cache
php -r '
require $argv[1]."/script/bootstrap-gen0-manifest-lib.php";
$m = bootstrap_gen0_manifest_stamp_lowering_fingerprint($argv[1]);
fwrite(STDOUT, "bootstrap-gen0-sync-lowering-stamp: stamped provenance="
    .(string) ($m["provenance"] ?? "?")." fingerprint="
    .substr((string) ($m["lowering_source_fingerprint"] ?? ""), 0, 16)."…\n");
' "${ROOT}"

php "${ROOT}/script/check-bootstrap-gen0-manifest-sync.php"
echo "bootstrap-gen0-sync-lowering-stamp: OK"
