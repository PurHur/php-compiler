#!/usr/bin/env bash
# Honest Zend rebuild of prelinked/bootstrap-gen0/bin-compile-aot (#23468).
#
# Spine sidecar refresh (bootstrap-refresh-gen0-sidecar.sh) updates compiler_lib
# blobs; it only copies bin-compile-aot when build/ already has one. The committed
# argv driver has been stale since 2026-06-15 and fails parseAndCompile on every
# input — this script Zend-compiles bin/compile.php into that seed.
#
# Prefer the exclusive Docker launcher for multi-hour runs (#22642):
#   BOOTSTRAP_GEN0_REFRESH_ARGV_DRIVER=1 ./script/bootstrap-gen0-refresh-exclusive-docker.sh
# or standalone after a spine refresh:
#   ./script/bootstrap-gen0-refresh-argv-driver.sh
#
# Requires LLVM 9 + large PHP heap (default 24576M). Does not restamp without a
# build receipt — uses bootstrap-refresh-gen0-sidecar provenance helpers.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "${ROOT}"

# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
# shellcheck source=bootstrap-gen0-install-prelinked-driver.sh
source "$(dirname "$0")/bootstrap-gen0-install-prelinked-driver.sh"
# shellcheck source=bootstrap-lowering-freshness.sh
source "$(dirname "$0")/bootstrap-lowering-freshness.sh"

ci_apply_llvm_memory_env

ENTRY="${ROOT}/bin/compile.php"
OUT="${ROOT}/build/bin-compile-aot"
PRELINKED="${ROOT}/prelinked/bootstrap-gen0"
MEM="${PHP_COMPILER_MEMORY_LIMIT:-24576M}"

if [[ ! -f "${ENTRY}" ]]; then
  echo "bootstrap-gen0-refresh-argv-driver: missing ${ENTRY}" >&2
  exit 1
fi
if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-gen0-refresh-argv-driver: LLVM 9 not found (script/install-llvm9.sh)" >&2
  exit 2
fi

export PHP_COMPILER_CI_RAM_GB=0
if command -v ci_apply_resource_limits >/dev/null 2>&1; then
  ci_apply_resource_limits || true
fi
ulimit -v unlimited 2>/dev/null || ulimit -v 0 2>/dev/null || true
export PHP_COMPILER_MEMORY_LIMIT="${MEM}"
export PHP_COMPILER_LLVM_MEMORY_LIMIT="${MEM}"
export PHP_COMPILER_INCLUDE_SCOPE_REMAP="${PHP_COMPILER_INCLUDE_SCOPE_REMAP:-0}"
export PHP_COMPILER_HELPER_RUNTIME_O="${PHP_COMPILER_HELPER_RUNTIME_O:-0}"
export PHPCFG_SIMPLIFIER_USECHAIN="${PHPCFG_SIMPLIFIER_USECHAIN:-1}"
unset PHPCFG_SIMPLIFIER_LEGACY

# Full cli_driver + native $argv bridge — same knobs as
# bootstrap-selfhost-driver-host-compile.sh with BOOTSTRAP_M5_DRIVER_HOST_FULL_CLI=1.
# Without these, Zend emits a link-only stub that fails functional smoke (#23468).
export PHP_COMPILER_SELFHOST_AOT=1
export PHP_COMPILER_M3_COMPILE_DRIVER=1
export PHP_COMPILER_M3_COMPILE_DRIVER_MAIN=1
export PHP_COMPILER_M5_DRIVER_HOST=1
export PHP_COMPILER_M4_BIN_COMPILE_DRIVER=1
export PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1
export PHP_COMPILER_EMIT_HELPER_LINK=1
export PHP_COMPILER_CLI_COMPILED=1
export PHP_COMPILER_CLI_SKIP_VENDOR=1
export PHP_COMPILER_M3_EMIT_LOG_PREFIX="${PHP_COMPILER_M3_EMIT_LOG_PREFIX:-helloworld_compile_smoke}"
unset PHP_COMPILER_M3_EMIT_TU

mkdir -p "${ROOT}/build" "${PRELINKED}"
rm -f "${OUT}"
export PHP_COMPILER_JIT_PROGRESS_FILE="${ROOT}/build/.last-jit-gen0-argv-driver"
export PHP_COMPILER_JIT_ENTRY_FILE="${ROOT}/build/.last-jit-gen0-argv-driver-entry"
export PHP_COMPILER_JIT_PHASE_FILE="${ROOT}/build/.last-jit-gen0-argv-driver-phase"

echo "bootstrap-gen0-refresh-argv-driver: Zend compile ${ENTRY} -> ${OUT} (mem=${MEM})"
echo "bootstrap-gen0-refresh-argv-driver: ulimit -v=$(ulimit -v) M5_DRIVER_HOST=1"

set +e
env PHP_COMPILER_MEMORY_LIMIT="${MEM}" \
  php -d "memory_limit=${MEM}" \
  "${ROOT}/bin/compile.php" -o "${OUT}" "${ENTRY}"
rc=$?
set -e

if [[ "${rc}" -ne 0 || ! -x "${OUT}" ]]; then
  echo "bootstrap-gen0-refresh-argv-driver: Zend compile failed (exit ${rc})" >&2
  echo "bootstrap-gen0-refresh-argv-driver: last progress=$(cat "${PHP_COMPILER_JIT_PROGRESS_FILE}" 2>/dev/null || echo none)" >&2
  echo "bootstrap-gen0-refresh-argv-driver: last entry=$(cat "${PHP_COMPILER_JIT_ENTRY_FILE}" 2>/dev/null || echo none)" >&2
  exit 1
fi

driver_bytes="$(wc -c <"${OUT}")"
echo "bootstrap-gen0-refresh-argv-driver: produced ${OUT} (${driver_bytes} bytes)"

# Functional check before publishing — refuse to ship another broken seed (#23468).
export BOOTSTRAP_GEN0_DRIVER="${OUT}"
if ! bash "${ROOT}/script/bootstrap-gen0-driver-functional-smoke.sh"; then
  echo "bootstrap-gen0-refresh-argv-driver: refusing publish — new driver failed functional smoke (#23468)" >&2
  exit 1
fi

cp -f "${OUT}" "${PRELINKED}/bin-compile-aot"
cp -f "${OUT}" "${PRELINKED}/.m3_bin_compile_aot_blob"
chmod +x "${PRELINKED}/bin-compile-aot" "${PRELINKED}/.m3_bin_compile_aot_blob"
cp -f "${OUT}" "${ROOT}/build/.m3_bin_compile_aot_blob"
chmod +x "${ROOT}/build/.m3_bin_compile_aot_blob"

bootstrap_lowering_source_write_build_stamp
echo "==> record gen-0 build receipt for argv driver artifacts"
php -r '
require $argv[1]."/script/bootstrap-gen0-manifest-lib.php";
$r = bootstrap_gen0_write_build_receipt($argv[1]);
fwrite(STDOUT, "bootstrap-gen0-refresh-argv-driver: receipt fingerprint="
    .substr((string) $r["lowering_source_fingerprint"], 0, 16)."… artifacts="
    .count($r["artifacts"])."\n");
' "${ROOT}"

php "${ROOT}/script/bootstrap-gen0-manifest-refresh.php"
php -r '
require $argv[1]."/script/bootstrap-gen0-manifest-lib.php";
$m = bootstrap_gen0_manifest_stamp_lowering_fingerprint($argv[1]);
fwrite(STDOUT, "bootstrap-gen0-refresh-argv-driver: stamped lowering_source_fingerprint="
    .substr((string) ($m["lowering_source_fingerprint"] ?? ""), 0, 16)."…\n");
' "${ROOT}"

php "${ROOT}/script/check-bootstrap-gen0-manifest-sync.php"

# Re-run smoke against the committed path (not just build/).
unset BOOTSTRAP_GEN0_DRIVER
bash "${ROOT}/script/bootstrap-gen0-driver-functional-smoke.sh"

echo "bootstrap-gen0-refresh-argv-driver: OK — committed gen-0 argv driver refreshed (#23468)"
