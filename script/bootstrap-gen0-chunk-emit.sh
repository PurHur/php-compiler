#!/usr/bin/env bash
# Emit one spine chunk as a cached translation unit with a resumable receipt (#36147).
#
# Skips re-emit when the receipt fingerprint matches live lowering sources and the
# binary is present. Crash mid-chunk only loses that chunk's work, not the full spine.
#
# Usage:
#   CHUNK_ID=ext-ds CHUNK_ENTRY=build/micro/chunk/ds.php ./script/bootstrap-gen0-chunk-emit.sh
#   CHUNK_ID=lib-vm-hub CHUNK_ENTRY=test/selfhost/vm_unit_probe/main.php ./script/bootstrap-gen0-chunk-emit.sh
#
# Env:
#   CHUNK_OUT_DIR          default build/chunks
#   CHUNK_FORCE=1          ignore fresh receipt
#   PHP_COMPILER_SPINE_CHUNK=1  default 1
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "${ROOT}"

# shellcheck source=bootstrap-lowering-freshness.sh
source "${ROOT}/script/bootstrap-lowering-freshness.sh"
# shellcheck source=php-env.sh
source "${ROOT}/script/php-env.sh"

CHUNK_ID="${CHUNK_ID:-}"
CHUNK_ENTRY="${CHUNK_ENTRY:-}"
OUT_DIR="${CHUNK_OUT_DIR:-${ROOT}/build/chunks}"
FORCE="${CHUNK_FORCE:-0}"

if [[ -z "${CHUNK_ID}" || -z "${CHUNK_ENTRY}" ]]; then
  echo "bootstrap-gen0-chunk-emit: set CHUNK_ID and CHUNK_ENTRY" >&2
  exit 2
fi
if [[ "${CHUNK_ENTRY}" != /* ]]; then
  CHUNK_ENTRY="${ROOT}/${CHUNK_ENTRY#./}"
fi
if [[ ! -f "${CHUNK_ENTRY}" ]]; then
  echo "bootstrap-gen0-chunk-emit: missing entry ${CHUNK_ENTRY}" >&2
  exit 1
fi

export ROOT
mkdir -p "${OUT_DIR}"

BIN="${OUT_DIR}/${CHUNK_ID}.bin"
LOG="${OUT_DIR}/${CHUNK_ID}.log"
RECEIPT="${OUT_DIR}/${CHUNK_ID}.receipt.json"
STUBS="${OUT_DIR}/${CHUNK_ID}.stubs.json"

want_fp="$(bootstrap_lowering_source_fingerprint)"
have_fp=""
if [[ -f "${RECEIPT}" ]]; then
  have_fp="$(php -r '
    $r = json_decode(file_get_contents($argv[1]), true);
    echo is_array($r) ? (string)($r["lowering_source_fingerprint"] ?? "") : "";
  ' "${RECEIPT}" 2>/dev/null || true)"
fi

if [[ "${FORCE}" != "1" && -x "${BIN}" && "${want_fp}" == "${have_fp}" && -n "${have_fp}" ]]; then
  echo "bootstrap-gen0-chunk-emit: fresh receipt — skip ${CHUNK_ID} (${BIN})"
  exit 0
fi

echo "bootstrap-gen0-chunk-emit: emit ${CHUNK_ID} ← ${CHUNK_ENTRY}"
rm -f "${BIN}"
start=$(date +%s)

set +e
env PHP_COMPILER_SPINE_CHUNK="${PHP_COMPILER_SPINE_CHUNK:-1}" \
  PHP_COMPILER_REPORT_EXTERNAL_STUBS=1 \
  PHP_COMPILER_EXTERNAL_STUBS_JSON="${STUBS}" \
  php bin/compile.php -o "${BIN}" "${CHUNK_ENTRY}" >"${LOG}" 2>&1
rc=$?
set -e

elapsed=$(( $(date +%s) - start ))
size=0
[[ -f "${BIN}" ]] && size=$(wc -c <"${BIN}")

stub_count=0
if [ -f "${STUBS}" ]; then
  stub_count="$(php -r 'echo (int)(json_decode(file_get_contents($argv[1]), true)["stub_count"] ?? 0);' "${STUBS}" 2>/dev/null || echo 0)"
elif grep -q 'external method stubs' "${LOG}" 2>/dev/null; then
  php "${ROOT}/script/spine-chunk-stub-export.php" --write "${STUBS}" "${LOG}" >/dev/null || true
  stub_count="$(php -r 'echo (int)(json_decode(file_get_contents($argv[1]), true)["stub_count"] ?? 0);' "${STUBS}" 2>/dev/null || echo 0)"
fi

php -r '
$receipt = [
    "chunk_id" => $argv[1],
    "entry" => $argv[2],
    "bin" => $argv[3],
    "lowering_source_fingerprint" => $argv[4],
    "exit_code" => (int) $argv[5],
    "size_bytes" => (int) $argv[6],
    "wall_seconds" => (int) $argv[7],
    "stub_count" => (int) $argv[8],
    "generated_at" => gmdate("c"),
];
file_put_contents($argv[9], json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
' "${CHUNK_ID}" "${CHUNK_ENTRY}" "${BIN}" "${want_fp}" "${rc}" "${size}" "${elapsed}" "${stub_count}" "${RECEIPT}"

if [[ "${rc}" -ne 0 || ! -x "${BIN}" ]]; then
  echo "bootstrap-gen0-chunk-emit: FAILED ${CHUNK_ID} rc=${rc} (${elapsed}s) — see ${LOG}" >&2
  exit 1
fi

echo "bootstrap-gen0-chunk-emit: OK ${CHUNK_ID} ${size} bytes ${elapsed}s stubs=${stub_count}"
if [[ "${stub_count}" -gt 0 ]]; then
  echo "bootstrap-gen0-chunk-emit: ${STUBS} — bind before linking this chunk into gen-0" >&2
fi
exit 0
