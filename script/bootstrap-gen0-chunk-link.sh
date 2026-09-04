#!/usr/bin/env bash
# Combine (and optionally executable-link) emitted gen-0 chunk `.o` files (#36387).
#
# Default: `ld -r` combine → combined.o + link.receipt.json (honest hub-core checkpoint).
# CHUNK_LINK_EXECUTABLE=1 also links a runnable .bin via HelperRuntimeCache slug adoption
# (requires per-chunk .helpers.json from object-only emit).
#
# Usage:
#   ./script/bootstrap-gen0-chunk-link.sh
#   CHUNK_OUT_DIR=build/chunks ./script/bootstrap-gen0-chunk-link.sh
#   CHUNK_LINK_EXECUTABLE=1 ./script/bootstrap-gen0-chunk-link.sh --plan=build/chunks/plan.json
#
# Env:
#   CHUNK_OUT_DIR              default build/chunks
#   CHUNK_COMBINED_O           default OUT_DIR/combined.o
#   CHUNK_COMBINED_BIN         default OUT_DIR/combined.bin
#   CHUNK_LINK_EXECUTABLE=1    also produce a runnable binary
#   CHUNK_LINK_FORCE=1         ignore fresh link receipt
#   PHP_COMPILER_HELPER_RUNTIME_O=1  default 1 when executable-linking
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "${ROOT}"

# shellcheck source=bootstrap-lowering-freshness.sh
source "${ROOT}/script/bootstrap-lowering-freshness.sh"
# shellcheck source=php-env.sh
source "${ROOT}/script/php-env.sh"

OUT_DIR="${CHUNK_OUT_DIR:-${ROOT}/build/chunks}"
PLAN=""
LINK_EXE="${CHUNK_LINK_EXECUTABLE:-0}"
FORCE="${CHUNK_LINK_FORCE:-0}"
COMBINED_O="${CHUNK_COMBINED_O:-}"
COMBINED_BIN="${CHUNK_COMBINED_BIN:-}"

usage() {
  cat <<'EOF' >&2
Usage: bootstrap-gen0-chunk-link.sh [--plan=PATH]
  Combines OK chunk objects from OUT_DIR (plan order when --plan / plan.json present).
  CHUNK_LINK_EXECUTABLE=1 also links combined.o → combined.bin with helper slug adoption.
EOF
}

for arg in "$@"; do
  case "${arg}" in
    --plan=*) PLAN="${arg#*=}" ;;
    -h|--help) usage; exit 0 ;;
    *) echo "bootstrap-gen0-chunk-link: unknown argument ${arg}" >&2; usage; exit 2 ;;
  esac
done

if [[ "${OUT_DIR}" != /* ]]; then
  OUT_DIR="${ROOT}/${OUT_DIR#./}"
fi
if [[ -z "${PLAN}" && -f "${OUT_DIR}/plan.json" ]]; then
  PLAN="${OUT_DIR}/plan.json"
fi
if [[ -n "${PLAN}" && "${PLAN}" != /* ]]; then
  PLAN="${ROOT}/${PLAN#./}"
fi

COMBINED_O="${COMBINED_O:-${OUT_DIR}/combined.o}"
COMBINED_BIN="${COMBINED_BIN:-${OUT_DIR}/combined.bin}"
RECEIPT="${OUT_DIR}/link.receipt.json"
LOG="${OUT_DIR}/link.log"

if [[ ! -d "${OUT_DIR}" ]]; then
  echo "bootstrap-gen0-chunk-link: missing OUT_DIR ${OUT_DIR}" >&2
  exit 1
fi

want_fp="$(bootstrap_lowering_source_fingerprint)"
if [[ "${FORCE}" != "1" && -f "${COMBINED_O}" && -s "${COMBINED_O}" && -f "${RECEIPT}" ]]; then
  have_fp="$(php -r '
    $r = json_decode(file_get_contents($argv[1]), true);
    echo is_array($r) ? (string)($r["lowering_source_fingerprint"] ?? "") : "";
  ' "${RECEIPT}" 2>/dev/null || true)"
  exe_ok=1
  if [[ "${LINK_EXE}" == "1" ]]; then
    exe_ok=0
    [[ -f "${COMBINED_BIN}" && -s "${COMBINED_BIN}" ]] && exe_ok=1
  fi
  if [[ -n "${have_fp}" && "${have_fp}" == "${want_fp}" && "${exe_ok}" -eq 1 ]]; then
    echo "bootstrap-gen0-chunk-link: fresh receipt — skip (${COMBINED_O})"
    exit 0
  fi
fi

echo "bootstrap-gen0-chunk-link: combine ← ${OUT_DIR}"
start=$(date +%s)
rm -f "${COMBINED_O}" "${COMBINED_BIN}" "${LOG}"

export CHUNK_OUT_DIR="${OUT_DIR}"
export CHUNK_PLAN="${PLAN}"
export CHUNK_COMBINED_O="${COMBINED_O}"
export CHUNK_COMBINED_BIN="${COMBINED_BIN}"
export CHUNK_LINK_EXECUTABLE="${LINK_EXE}"
export CHUNK_LINK_RECEIPT="${RECEIPT}"
export CHUNK_LINK_WANT_FP="${want_fp}"
export PHP_COMPILER_HELPER_RUNTIME_O="${PHP_COMPILER_HELPER_RUNTIME_O:-1}"

set +e
php "${ROOT}/script/bootstrap-gen0-chunk-link.php" >"${LOG}" 2>&1
rc=$?
set -e
elapsed=$(( $(date +%s) - start ))

if [[ "${rc}" -ne 0 || ! -f "${COMBINED_O}" || ! -s "${COMBINED_O}" ]]; then
  echo "bootstrap-gen0-chunk-link: FAILED rc=${rc} (${elapsed}s) — see ${LOG}" >&2
  tail -40 "${LOG}" >&2 || true
  exit 1
fi

size=$(wc -c <"${COMBINED_O}")
echo "bootstrap-gen0-chunk-link: OK combined=${COMBINED_O} ${size} bytes ${elapsed}s"
if [[ "${LINK_EXE}" == "1" ]]; then
  if [[ ! -f "${COMBINED_BIN}" || ! -s "${COMBINED_BIN}" ]]; then
    echo "bootstrap-gen0-chunk-link: FAILED missing executable ${COMBINED_BIN} — see ${LOG}" >&2
    tail -40 "${LOG}" >&2 || true
    exit 1
  fi
  echo "bootstrap-gen0-chunk-link: OK executable=${COMBINED_BIN} $(wc -c <"${COMBINED_BIN}") bytes"
fi
exit 0
