#!/usr/bin/env bash
# Parallel / resumable gen-0 split-TU chunk emit (#36387 / #36147).
#
# Drives script/bootstrap-gen0-chunk-emit.sh across a plan from
# script/bootstrap-gen0-chunk-plan.php. Per-chunk receipts make a second run a
# no-op when lowering fingerprints match; wall clock is process elapsed, not
# the sum of serial chunk times.
#
# Waves: hub/requires (wave 0) finish before lib/ext/spine consumers so peer
# manifests in CHUNK_OUT_DIR can bind cross-chunk symbols (#36155 Phase C).
#
# Usage:
#   ./script/bootstrap-gen0-chunks.sh --micro=4
#   ./script/bootstrap-gen0-chunks.sh --plan=build/chunks/plan.json
#   ./script/bootstrap-gen0-chunks.sh --lib=AOT,Lint --ext=types
#   ./script/bootstrap-gen0-chunks.sh --spine --strategy=sub --max-files=80
#   CHUNK_JOBS=4 CHUNK_FORCE=1 ./script/bootstrap-gen0-chunks.sh --micro=4
#
# Env:
#   CHUNK_JOBS          parallel workers (default: nproc-2, min 1)
#   CHUNK_OUT_DIR       object/receipt directory (default build/chunks)
#   CHUNK_FORCE=1       ignore fresh receipts
#   CHUNK_LINK_BINARY=1 pass through (full link; slow)
#   CHUNK_KEEP_GOING=1  finish the queue even after a failure
#   CHUNK_WAVE_BARRIER=0  disable wave barriers (parallel across waves; peer manifests race)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "${ROOT}"

# shellcheck source=php-env.sh
source "${ROOT}/script/php-env.sh"

PLAN=""
MICRO=""
EXTS=""
LIBS=""
REQUIRES=""
SPINE=0
STRATEGY=""
MAX_FILES=""
OUT_DIR="${CHUNK_OUT_DIR:-${ROOT}/build/chunks}"
JOBS="${CHUNK_JOBS:-0}"
FORCE="${CHUNK_FORCE:-0}"
LINK_BINARY="${CHUNK_LINK_BINARY:-0}"
KEEP_GOING="${CHUNK_KEEP_GOING:-0}"
WAVE_BARRIER="${CHUNK_WAVE_BARRIER:-1}"

usage() {
  cat <<'EOF' >&2
Usage: bootstrap-gen0-chunks.sh [plan opts | --plan=PATH]
  --micro[=N]          generate + emit N micro fixtures (default 4)
  --plan=PATH          emit chunks listed in an existing plan JSON
  --ext=a,b            extension directory chunks
  --lib=AOT,Lint       lib/<name> directory chunks
  --requires=PATH      hub chunk from a requires list
  --spine              partition compiler_lib_spine_smoke/main.php
  --strategy=dir|sub|hub  spine partition strategy (with --spine)
  --max-files=N        further split buckets larger than N files
EOF
}

for arg in "$@"; do
  case "${arg}" in
    --micro) MICRO=4 ;;
    --micro=*) MICRO="${arg#*=}" ;;
    --plan=*) PLAN="${arg#*=}" ;;
    --ext=*) EXTS="${arg#*=}" ;;
    --lib=*) LIBS="${arg#*=}" ;;
    --requires=*) REQUIRES="${arg#*=}" ;;
    --spine) SPINE=1 ;;
    --strategy=*) STRATEGY="${arg#*=}" ;;
    --max-files=*) MAX_FILES="${arg#*=}" ;;
    -h|--help) usage; exit 0 ;;
    *) echo "bootstrap-gen0-chunks: unknown argument ${arg}" >&2; usage; exit 2 ;;
  esac
done

if [[ -z "${PLAN}" && -z "${MICRO}" && -z "${EXTS}" && -z "${LIBS}" && -z "${REQUIRES}" && "${SPINE}" -eq 0 ]]; then
  MICRO=4
fi

mkdir -p "${OUT_DIR}"
SUMMARY="${OUT_DIR}/summary.json"
LOG_DIR="${OUT_DIR}/orchestrator-logs"
STATUS_DIR="${OUT_DIR}/orchestrator-status"
rm -rf "${STATUS_DIR}"
mkdir -p "${LOG_DIR}" "${STATUS_DIR}"

if [[ -z "${PLAN}" ]]; then
  PLAN="${OUT_DIR}/plan.json"
  plan_args=(--plan-out="${PLAN}" --entries-dir="${OUT_DIR}/entries")
  if [[ -n "${MICRO}" ]]; then
    plan_args+=(--micro="${MICRO}")
  fi
  if [[ -n "${EXTS}" ]]; then
    plan_args+=(--ext="${EXTS}")
  fi
  if [[ -n "${LIBS}" ]]; then
    plan_args+=(--lib="${LIBS}")
  fi
  if [[ -n "${REQUIRES}" ]]; then
    plan_args+=(--requires="${REQUIRES}")
  fi
  if [[ "${SPINE}" -eq 1 ]]; then
    plan_args+=(--spine)
  fi
  if [[ -n "${STRATEGY}" ]]; then
    plan_args+=(--strategy="${STRATEGY}")
  fi
  if [[ -n "${MAX_FILES}" ]]; then
    plan_args+=(--max-files="${MAX_FILES}")
  fi
  php "${ROOT}/script/bootstrap-gen0-chunk-plan.php" "${plan_args[@]}"
fi

if [[ ! -f "${PLAN}" ]]; then
  echo "bootstrap-gen0-chunks: missing plan ${PLAN}" >&2
  exit 1
fi

if [[ "${JOBS}" -lt 1 ]]; then
  nproc_n="$(nproc 2>/dev/null || echo 1)"
  if [[ "${nproc_n}" -gt 2 ]]; then
    JOBS=$((nproc_n - 2))
  else
    JOBS=1
  fi
fi
if [[ "${JOBS}" -lt 1 ]]; then
  JOBS=1
fi

# Wave TSV files: one per wave, lines chunk_id<TAB>entry
WAVE_DIR="$(mktemp -d)"
trap 'rm -rf "${WAVE_DIR}"' EXIT

php -r '
$p = json_decode(file_get_contents($argv[1]), true);
if (!is_array($p) || !isset($p["chunks"]) || !is_array($p["chunks"]) || $p["chunks"] === []) {
    fwrite(STDERR, "bootstrap-gen0-chunks: invalid or empty plan\n");
    exit(1);
}
$dir = $argv[2];
$waves = [];
foreach ($p["chunks"] as $c) {
    if (!is_array($c) || empty($c["chunk_id"]) || empty($c["entry"])) {
        fwrite(STDERR, "bootstrap-gen0-chunks: plan chunk missing chunk_id/entry\n");
        exit(1);
    }
    $w = (int) ($c["wave"] ?? 0);
    $waves[$w][] = $c["chunk_id"] . "\t" . $c["entry"];
}
ksort($waves, SORT_NUMERIC);
$names = [];
foreach ($waves as $w => $lines) {
    $path = $dir . "/wave-" . $w . ".tsv";
    file_put_contents($path, implode("\n", $lines) . "\n");
    $names[] = (string) $w;
}
echo implode(" ", $names), "\n";
' "${PLAN}" "${WAVE_DIR}" >"${WAVE_DIR}/waves.txt"

WAVE_LIST="$(tr -d '\n' <"${WAVE_DIR}/waves.txt")"
total="$(php -r '
$p = json_decode(file_get_contents($argv[1]), true);
echo (int) ($p["chunk_count"] ?? count($p["chunks"] ?? []));
' "${PLAN}")"

if [[ "${total}" -eq 0 ]]; then
  echo "bootstrap-gen0-chunks: plan has zero chunks" >&2
  exit 1
fi

echo "bootstrap-gen0-chunks: ${total} chunk(s), ${JOBS} job(s), waves=[${WAVE_LIST}], barrier=${WAVE_BARRIER}, out=${OUT_DIR}"

wall_start=$(date +%s)
fail_seen=0

start_one() {
  local id="$1"
  local entry="$2"
  local log="${LOG_DIR}/${id}.orchestrator.log"
  (
    set +e
    env CHUNK_ID="${id}" \
      CHUNK_ENTRY="${entry}" \
      CHUNK_OUT_DIR="${OUT_DIR}" \
      CHUNK_FORCE="${FORCE}" \
      CHUNK_LINK_BINARY="${LINK_BINARY}" \
      ./script/bootstrap-gen0-chunk-emit.sh >"${log}" 2>&1
    rc=$?
    if [[ "${rc}" -eq 0 ]] && grep -q 'fresh receipt — skip' "${log}" 2>/dev/null; then
      echo SKIP >"${STATUS_DIR}/${id}"
    elif [[ "${rc}" -eq 0 ]]; then
      echo OK >"${STATUS_DIR}/${id}"
    else
      echo FAIL >"${STATUS_DIR}/${id}"
    fi
    exit "${rc}"
  ) &
}

run_queue_file() {
  local queue_file="$1"
  local jobs_cap="$2"
  local running=0
  local queue_done=0
  exec 3<"${queue_file}"
  while true; do
    while [[ "${queue_done}" -eq 0 && "${running}" -lt "${jobs_cap}" ]]; do
      if [[ "${fail_seen}" -ne 0 && "${KEEP_GOING}" != "1" ]]; then
        break
      fi
      if ! IFS=$'\t' read -r cid entry <&3; then
        queue_done=1
        break
      fi
      [[ -z "${cid}" ]] && continue
      start_one "${cid}" "${entry}"
      running=$((running + 1))
    done
    if [[ "${running}" -eq 0 ]]; then
      break
    fi
    set +e
    wait -n
    wr=$?
    set -e
    running=$((running - 1))
    if [[ "${wr}" -ne 0 ]]; then
      fail_seen=1
    fi
  done
  set +e
  wait
  set -e
  exec 3<&-
}

if [[ "${WAVE_BARRIER}" == "0" ]]; then
  # Flatten all waves into one parallel queue (peer manifests race).
  flat="$(mktemp)"
  for w in ${WAVE_LIST}; do
    cat "${WAVE_DIR}/wave-${w}.tsv" >>"${flat}"
  done
  run_queue_file "${flat}" "${JOBS}"
  rm -f "${flat}"
else
  for w in ${WAVE_LIST}; do
    if [[ "${fail_seen}" -ne 0 && "${KEEP_GOING}" != "1" ]]; then
      break
    fi
    wave_file="${WAVE_DIR}/wave-${w}.tsv"
    wave_n="$(grep -c . "${wave_file}" || true)"
    echo "bootstrap-gen0-chunks: wave ${w} — ${wave_n} chunk(s)"
    run_queue_file "${wave_file}" "${JOBS}"
  done
fi

wall=$(( $(date +%s) - wall_start ))

ok=0
skip=0
fail=0
results_args=()
status_n=0
if [[ -d "${STATUS_DIR}" ]]; then
  while IFS= read -r st; do
    [[ -z "${st}" ]] && continue
    status_n=$((status_n + 1))
    id="$(basename "${st}")"
    status="$(tr -d '\n' <"${st}")"
    results_args+=("${id}=${status}")
    case "${status}" in
      OK) ok=$((ok + 1)) ;;
      SKIP) skip=$((skip + 1)); ok=$((ok + 1)) ;;
      *) fail=$((fail + 1))
         echo "bootstrap-gen0-chunks: FAILED ${id} — see ${LOG_DIR}/${id}.orchestrator.log" >&2
         ;;
    esac
  done < <(find "${STATUS_DIR}" -mindepth 1 -maxdepth 1 -type f | LC_ALL=C sort)
fi

# Chunks never started (stop-on-fail) count as fail for honesty.
unstarted=$((total - status_n))
if [[ "${unstarted}" -gt 0 ]]; then
  fail=$((fail + unstarted))
fi

if [[ ${#results_args[@]} -eq 0 ]]; then
  RESULTS_JSON='{}'
else
  RESULTS_JSON="$(php -r '
$pairs = [];
foreach (array_slice($argv, 1) as $pair) {
    $eq = strpos($pair, "=");
    if ($eq === false) {
        continue;
    }
    $pairs[substr($pair, 0, $eq)] = substr($pair, $eq + 1);
}
echo json_encode($pairs);
' -- "${results_args[@]}")"
fi

php -r '
$summary = [
    "version" => 2,
    "generated_at" => gmdate("c"),
    "plan" => $argv[1],
    "out_dir" => $argv[2],
    "jobs" => (int) $argv[3],
    "wall_seconds" => (int) $argv[4],
    "total" => (int) $argv[5],
    "ok" => (int) $argv[6],
    "skip" => (int) $argv[7],
    "fail" => (int) $argv[8],
    "wave_barrier" => $argv[9] === "1",
    "results" => json_decode($argv[10], true) ?: new stdClass(),
    "note" => "Wave-barrier object-only gen-0 chunk emit with peer-manifest bind + receipt resume (#36387).",
];
file_put_contents($argv[11], json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
' "${PLAN}" "${OUT_DIR}" "${JOBS}" "${wall}" "${total}" "${ok}" "${skip}" "${fail}" \
  "${WAVE_BARRIER}" "${RESULTS_JSON}" "${SUMMARY}"

echo "bootstrap-gen0-chunks: wall=${wall}s total=${total} ok=${ok} skip=${skip} fail=${fail} summary=${SUMMARY}"

if [[ "${fail}" -gt 0 ]]; then
  exit 1
fi
exit 0
