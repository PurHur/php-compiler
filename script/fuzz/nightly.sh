#!/usr/bin/env bash
# Nightly differential fuzz batch (#36398).
#
# Done-when gate: 2,000 VM programs in < 60 minutes. Unique failures are reduced
# and scored for ≤15 nonempty-line reproducers (target ≥ 80 % when n ≥ 5).
#
# Usage:
#   ./script/fuzz/nightly.sh
#   FUZZ_NIGHTLY_COUNT=200 FUZZ_NIGHTLY_WALL_SEC=600 ./script/fuzz/nightly.sh
#
# Re-execs via docker-exec when not already in the CI image.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

COUNT="${FUZZ_NIGHTLY_COUNT:-2000}"
SEED_BASE="${FUZZ_NIGHTLY_SEED_BASE:-1}"
BACKEND="${FUZZ_NIGHTLY_BACKEND:-vm}"
WALL_SEC="${FUZZ_NIGHTLY_WALL_SEC:-3600}"
OUTDIR="${FUZZ_NIGHTLY_OUTDIR:-build/fuzz-nightly}"
KEEP="${FUZZ_NIGHTLY_KEEP:-$OUTDIR/failures}"
REDUCE="${FUZZ_NIGHTLY_REDUCE:-1}"
MIN_REDUCE_SAMPLES="${FUZZ_NIGHTLY_MIN_REDUCE_SAMPLES:-5}"
REDUCE_TARGET_PCT="${FUZZ_NIGHTLY_REDUCE_TARGET_PCT:-80}"

if ! { [[ -f /.dockerenv ]] && [[ -f /opt/llvm9/libLLVM-9.so.1 ]]; } \
    && [[ "${PHP_COMPILER_IN_DOCKER:-0}" != "1" ]]; then
  exec ./script/docker-exec.sh -- bash -lc \
    "source script/php-env.sh && \
     FUZZ_NIGHTLY_COUNT=${COUNT} FUZZ_NIGHTLY_SEED_BASE=${SEED_BASE} \
     FUZZ_NIGHTLY_BACKEND=${BACKEND} FUZZ_NIGHTLY_WALL_SEC=${WALL_SEC} \
     FUZZ_NIGHTLY_OUTDIR=${OUTDIR} FUZZ_NIGHTLY_KEEP=${KEEP} \
     FUZZ_NIGHTLY_REDUCE=${REDUCE} ./script/fuzz/nightly.sh"
fi

# shellcheck source=php-env.sh
source script/php-env.sh

rm -rf "$OUTDIR"
mkdir -p "$OUTDIR" "$KEEP"

echo "fuzz-nightly: count=${COUNT} seed-base=${SEED_BASE} backend=${BACKEND} wall=${WALL_SEC}s"
START_TS="$(date +%s)"

set +e
timeout --foreground "$WALL_SEC" php script/fuzz/run.php \
  --count "$COUNT" \
  --seed-base "$SEED_BASE" \
  --backend "$BACKEND" \
  --quiet \
  --outdir "$OUTDIR/programs" \
  --keep-failures "$KEEP"
RUN_RC=$?
set -e

END_TS="$(date +%s)"
ELAPSED=$((END_TS - START_TS))

if [[ "$RUN_RC" -eq 124 ]]; then
  echo "fuzz-nightly: FAIL wall clock (${ELAPSED}s ≥ ${WALL_SEC}s) before finishing ${COUNT} programs" >&2
  exit 1
fi

# run.php exits 1 when mismatches exist — that is expected progress, not a wall failure.
if [[ "$RUN_RC" -gt 1 ]]; then
  echo "fuzz-nightly: FAIL runner exit ${RUN_RC}" >&2
  exit "$RUN_RC"
fi

UNIQUE=0
REDUCED_OK=0
REDUCED_LE15=0
if [[ "$REDUCE" == "1" ]]; then
  shopt -s nullglob
  for f in "$KEEP"/*.php; do
    UNIQUE=$((UNIQUE + 1))
    base="$(basename "$f" .php)"
    out="$OUTDIR/reduced/${base}.php"
    mkdir -p "$OUTDIR/reduced"
    set +e
    php script/fuzz/reduce.php --in "$f" --backend "$BACKEND" --out "$out" >"$OUTDIR/reduced/${base}.log" 2>&1
    rrc=$?
    set -e
    if [[ "$rrc" -eq 0 && -f "$out" ]]; then
      REDUCED_OK=$((REDUCED_OK + 1))
      nonempty="$(php -r 'require "script/fuzz/lib.php"; echo fuzz_count_nonempty_lines(file_get_contents($argv[1]));' "$out")"
      if [[ "$nonempty" -le 15 ]]; then
        REDUCED_LE15=$((REDUCED_LE15 + 1))
      fi
      echo "fuzz-nightly: reduced ${base} → ${nonempty} nonempty lines"
    else
      echo "fuzz-nightly: reduce skipped/failed for ${base} (rc=${rrc})" >&2
    fi
  done
  shopt -u nullglob
fi

LE15_PCT=100
if [[ "$REDUCED_OK" -gt 0 ]]; then
  LE15_PCT=$((REDUCED_LE15 * 100 / REDUCED_OK))
fi

REPORT="$OUTDIR/report.json"
php -r '
$report = [
  "count" => (int)$argv[1],
  "seed_base" => (int)$argv[2],
  "backend" => $argv[3],
  "elapsed_sec" => (int)$argv[4],
  "wall_sec" => (int)$argv[5],
  "runner_exit" => (int)$argv[6],
  "unique_failures" => (int)$argv[7],
  "reduced_ok" => (int)$argv[8],
  "reduced_le15" => (int)$argv[9],
  "reduced_le15_pct" => (int)$argv[10],
  "wall_ok" => ((int)$argv[4] < (int)$argv[5]),
];
file_put_contents($argv[11], json_encode($report, JSON_PRETTY_PRINT) . "\n");
echo json_encode($report, JSON_PRETTY_PRINT), "\n";
' "$COUNT" "$SEED_BASE" "$BACKEND" "$ELAPSED" "$WALL_SEC" "$RUN_RC" \
  "$UNIQUE" "$REDUCED_OK" "$REDUCED_LE15" "$LE15_PCT" "$REPORT"

echo "fuzz-nightly: seed corpus differential..."
./script/differential-sweep.sh --dir test/differential/cases/fuzz

# Wall gate: finished COUNT within WALL_SEC.
if [[ "$ELAPSED" -ge "$WALL_SEC" ]]; then
  echo "fuzz-nightly: FAIL elapsed ${ELAPSED}s ≥ wall ${WALL_SEC}s" >&2
  exit 1
fi

# Reducer quality gate when enough unique failures were reduced.
if [[ "$REDUCED_OK" -ge "$MIN_REDUCE_SAMPLES" && "$LE15_PCT" -lt "$REDUCE_TARGET_PCT" ]]; then
  echo "fuzz-nightly: FAIL reducer ≤15-line rate ${LE15_PCT}% < ${REDUCE_TARGET_PCT}% (n=${REDUCED_OK})" >&2
  exit 1
fi

echo "fuzz-nightly: OK (${ELAPSED}s for ${COUNT} programs; unique=${UNIQUE}; le15=${REDUCED_LE15}/${REDUCED_OK})"
exit 0
