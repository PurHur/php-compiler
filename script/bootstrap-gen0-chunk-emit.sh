#!/usr/bin/env bash
# Emit one spine chunk as a cached translation unit with a resumable receipt (#36147).
#
# Skips re-emit when the receipt fingerprint matches live lowering sources and the
# object is present. Crash mid-chunk only loses that chunk's work, not the full spine.
#
# Default: object-only emit (PHP_COMPILER_KEEP_OBJECT_FILE=1) + helper-runtime cache
# so chunk TUs avoid full ld of the helper corpus and post-link teardown (#36387).
#
# Usage:
#   CHUNK_ID=ext-ds CHUNK_ENTRY=build/micro/chunk/ds.php ./script/bootstrap-gen0-chunk-emit.sh
#   CHUNK_ID=lib-vm-hub CHUNK_ENTRY=test/selfhost/vm_unit_probe/main.php ./script/bootstrap-gen0-chunk-emit.sh
#
# Env:
#   CHUNK_OUT_DIR          default build/chunks
#   CHUNK_FORCE=1          ignore fresh receipt
#   CHUNK_LINK_BINARY=1    also link a runnable .bin (slow; probe-only)
#   CHUNK_PEER_MANIFESTS   colon-separated peer manifests (else auto from OUT_DIR/*.manifest.json)
#   CHUNK_NO_PEER_MANIFESTS=1  skip auto peer-manifest join
#   PHP_COMPILER_SPINE_CHUNK=1  default 1
#   PHP_COMPILER_HELPER_RUNTIME_O=1  default 1 for chunk emit
#   PHP_COMPILER_EXTERNAL_METHOD_MANIFEST  explicit override (wins over auto peers)
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
LINK_BINARY="${CHUNK_LINK_BINARY:-0}"

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

OBJ="${OUT_DIR}/${CHUNK_ID}.o"
BIN="${OUT_DIR}/${CHUNK_ID}.bin"
LOG="${OUT_DIR}/${CHUNK_ID}.log"
RECEIPT="${OUT_DIR}/${CHUNK_ID}.receipt.json"
STUBS="${OUT_DIR}/${CHUNK_ID}.stubs.json"
BITCODE="${OUT_DIR}/${CHUNK_ID}.bc"
MANIFEST="${OUT_DIR}/${CHUNK_ID}.manifest.json"

want_fp="$(bootstrap_lowering_source_fingerprint)"
have_fp=""
if [[ -f "${RECEIPT}" ]]; then
  have_fp="$(php -r '
    $r = json_decode(file_get_contents($argv[1]), true);
    echo is_array($r) ? (string)($r["lowering_source_fingerprint"] ?? "") : "";
  ' "${RECEIPT}" 2>/dev/null || true)"
fi

artifact="${OBJ}"
if [[ "${LINK_BINARY}" == "1" ]]; then
  artifact="${BIN}"
fi

if [[ "${FORCE}" != "1" && -f "${artifact}" && -s "${artifact}" && "${want_fp}" == "${have_fp}" && -n "${have_fp}" ]]; then
  echo "bootstrap-gen0-chunk-emit: fresh receipt — skip ${CHUNK_ID} (${artifact})"
  exit 0
fi

echo "bootstrap-gen0-chunk-emit: emit ${CHUNK_ID} ← ${CHUNK_ENTRY}"
rm -f "${OBJ}" "${BIN}"
start=$(date +%s)

# Unique helper-cache dir per chunk process (concurrent emits must not share).
: "${PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR:=${OUT_DIR}/.helper-cache-${CHUNK_ID}-$$}"
mkdir -p "${PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR}"
export PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR

keep_object=1
out_path="${OBJ}"
if [[ "${LINK_BINARY}" == "1" ]]; then
  keep_object=0
  out_path="${BIN}"
fi

# Peer manifests (#36155 Phase C / #36387): bind cross-chunk symbols from earlier waves.
# Explicit PHP_COMPILER_EXTERNAL_METHOD_MANIFEST wins; else CHUNK_PEER_MANIFESTS; else
# every *.manifest.json already in OUT_DIR except this chunk's own export path.
peer_manifests="${PHP_COMPILER_EXTERNAL_METHOD_MANIFEST:-}"
if [[ -z "${peer_manifests}" && "${CHUNK_NO_PEER_MANIFESTS:-0}" != "1" ]]; then
  if [[ -n "${CHUNK_PEER_MANIFESTS:-}" ]]; then
    peer_manifests="${CHUNK_PEER_MANIFESTS}"
  else
    peer_manifests="$(php -r '
$out = rtrim($argv[1], "/");
$self = $argv[2];
$paths = [];
foreach (glob($out . "/*.manifest.json") ?: [] as $p) {
    if (realpath($p) === realpath($self)) {
        continue;
    }
    if (is_file($p) && filesize($p) > 0) {
        $paths[] = $p;
    }
}
sort($paths, SORT_STRING);
echo implode(":", $paths);
' "${OUT_DIR}" "${MANIFEST}" 2>/dev/null || true)"
  fi
fi
if [[ -n "${peer_manifests}" ]]; then
  peer_n="$(php -r 'echo substr_count($argv[1], ":") + (trim($argv[1]) === "" ? 0 : 1);' "${peer_manifests}" 2>/dev/null || echo 0)"
  echo "bootstrap-gen0-chunk-emit: peer manifests=${peer_n}"
fi

set +e
env PHP_COMPILER_SPINE_CHUNK="${PHP_COMPILER_SPINE_CHUNK:-1}" \
  PHP_COMPILER_HELPER_RUNTIME_O="${PHP_COMPILER_HELPER_RUNTIME_O:-1}" \
  PHP_COMPILER_KEEP_OBJECT_FILE="${keep_object}" \
  PHP_COMPILER_REPORT_EXTERNAL_STUBS=1 \
  PHP_COMPILER_EXTERNAL_STUBS_JSON="${STUBS}" \
  PHP_COMPILER_EMIT_BITCODE="${BITCODE}" \
  PHP_COMPILER_EXTERNAL_METHOD_MANIFEST_EXPORT="${MANIFEST}" \
  PHP_COMPILER_EXTERNAL_METHOD_MANIFEST="${peer_manifests}" \
  php bin/compile.php -o "${out_path}" "${CHUNK_ENTRY}" >"${LOG}" 2>&1
rc=$?
set -e

elapsed=$(( $(date +%s) - start ))

# KEEP_OBJECT with -o ending in .o writes that path; with -o .bin writes .bin.o.
effective="${out_path}"
if [[ "${keep_object}" == "1" && "${out_path}" != *.o ]]; then
  effective="${out_path}.o"
fi
if [[ -f "${effective}" && "${effective}" != "${OBJ}" ]]; then
  mv -f "${effective}" "${OBJ}"
  effective="${OBJ}"
fi

size=0
[[ -f "${effective}" ]] && size=$(wc -c <"${effective}")

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
    "object" => $argv[3],
    "bin" => $argv[4],
    "lowering_source_fingerprint" => $argv[5],
    "exit_code" => (int) $argv[6],
    "size_bytes" => (int) $argv[7],
    "wall_seconds" => (int) $argv[8],
    "stub_count" => (int) $argv[9],
    "object_only" => $argv[10] === "1",
    "generated_at" => gmdate("c"),
];
file_put_contents($argv[11], json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
' "${CHUNK_ID}" "${CHUNK_ENTRY}" "${OBJ}" "${BIN}" "${want_fp}" "${rc}" "${size}" "${elapsed}" "${stub_count}" "${keep_object}" "${RECEIPT}"

if [[ "${rc}" -ne 0 || ! -f "${effective}" || "${size}" -le 0 ]]; then
  echo "bootstrap-gen0-chunk-emit: FAILED ${CHUNK_ID} rc=${rc} (${elapsed}s) — see ${LOG}" >&2
  exit 1
fi

echo "bootstrap-gen0-chunk-emit: OK ${CHUNK_ID} ${size} bytes ${elapsed}s stubs=${stub_count} artifact=${effective}"
if [[ "${stub_count}" -gt 0 ]]; then
  echo "bootstrap-gen0-chunk-emit: ${STUBS} — bind before linking this chunk into gen-0" >&2
fi
exit 0
