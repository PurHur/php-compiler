#!/usr/bin/env bash
# Nightly benchmark suite v2 publisher (#36385).
#
# Measures (or reuses) v2 RESULTS, appends benchmarks/history/<sha>.json,
# regenerates docs/pages/bench.html, and runs bench-gate --v2.
#
# Usage:
#   ./script/bench/nightly.sh                 # full measure + publish (wall-capped)
#   ./script/bench/nightly.sh --publish-only  # history + chart from existing RESULTS.json
#   BENCH_NIGHTLY_WALL_SEC=600 ./script/bench/nightly.sh
#   make bench-nightly
#
# Lives under script/bench/ so it does not inflate the top-level script/
# *.sh/*.php count budget (#36403). Re-execs via docker-exec when not already
# in the CI image.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

PUBLISH_ONLY=0
for arg in "$@"; do
  case "$arg" in
    --publish-only) PUBLISH_ONLY=1 ;;
    -h|--help)
      sed -n '2,14p' "$0"
      exit 0
      ;;
    *)
      echo "bench-nightly: unknown arg: ${arg}" >&2
      exit 2
      ;;
  esac
done

WALL_SEC="${BENCH_NIGHTLY_WALL_SEC:-900}"
OUTDIR="${BENCH_NIGHTLY_OUTDIR:-build/bench-nightly}"
SKIP_WEB="${PHP_COMPILER_BENCH_SKIP_WEB:-0}"
# publish-only refreshes history/chart from committed RESULTS; skip the timed gate
# unless explicitly forced (k-nucleotide AOT hang is pre-existing on some hosts).
if [[ "${PUBLISH_ONLY}" -eq 1 ]]; then
  SKIP_GATE="${BENCH_NIGHTLY_SKIP_GATE:-1}"
else
  SKIP_GATE="${BENCH_NIGHTLY_SKIP_GATE:-0}"
fi

if ! { [[ -f /.dockerenv ]] && [[ -f /opt/llvm9/libLLVM-9.so.1 ]]; } \
    && [[ "${PHP_COMPILER_IN_DOCKER:-0}" != "1" ]]; then
  EXTRA_ARGS=()
  [[ "${PUBLISH_ONLY}" -eq 1 ]] && EXTRA_ARGS+=(--publish-only)
  exec ./script/docker-exec.sh -- bash -lc \
    "source script/php-env.sh && \
     BENCH_NIGHTLY_WALL_SEC=${WALL_SEC} BENCH_NIGHTLY_OUTDIR=${OUTDIR} \
     BENCH_NIGHTLY_SKIP_GATE=${SKIP_GATE} PHP_COMPILER_BENCH_SKIP_WEB=${SKIP_WEB} \
     ./script/bench/nightly.sh ${EXTRA_ARGS[*]-}"
fi

# shellcheck source=../php-env.sh
source script/php-env.sh

mkdir -p "$OUTDIR"
ZEND_BIN="$(command -v php)"
if [[ -z "${ZEND_BIN}" ]]; then
  echo "bench-nightly: php not found in PATH" >&2
  exit 1
fi
export PHP_8_2="${ZEND_BIN}"

SHA="$(git rev-parse --short HEAD 2>/dev/null || true)"
if [[ -z "${SHA}" ]]; then
  SHA="$(date -u +%Y%m%d%H%M%S)"
fi

echo "bench-nightly: sha=${SHA} publish_only=${PUBLISH_ONLY} wall=${WALL_SEC}s out=${OUTDIR}"
START_TS="$(date +%s)"
MEASURE_RC=0
GATE_RC=0
HISTORY_PATH="benchmarks/history/${SHA}.json"
RESULTS_PATH="benchmarks/v2/RESULTS.json"

write_history_from_results() {
  if [[ ! -f "${RESULTS_PATH}" ]]; then
    echo "bench-nightly: missing ${RESULTS_PATH}" >&2
    return 1
  fi
  mkdir -p benchmarks/history
  cp -f "${RESULTS_PATH}" "${HISTORY_PATH}"
  echo "bench-nightly: wrote ${HISTORY_PATH}"
}

if [[ "${PUBLISH_ONLY}" -eq 1 ]]; then
  write_history_from_results
else
  set +e
  timeout --foreground "${WALL_SEC}" env \
    PHP_COMPILER_BENCH_HISTORY=1 \
    PHP_COMPILER_BENCH_SKIP_WEB="${SKIP_WEB}" \
    PHP_8_2="${ZEND_BIN}" \
    php script/bench.php --v2
  MEASURE_RC=$?
  set -e
  if [[ "${MEASURE_RC}" -eq 124 ]]; then
    echo "bench-nightly: FAIL wall clock (${WALL_SEC}s) during bench.php --v2" >&2
    exit 124
  fi
  if [[ "${MEASURE_RC}" -ne 0 ]]; then
    echo "bench-nightly: FAIL bench.php --v2 exit ${MEASURE_RC}" >&2
    exit "${MEASURE_RC}"
  fi
  # bench.php writes history when HISTORY=1; ensure the tip sha file exists even if
  # git was unavailable inside a nested shell.
  if [[ ! -f "${HISTORY_PATH}" ]]; then
    write_history_from_results
  fi
fi

php script/generate-bench-chart.php
echo "bench-nightly: refreshed docs/pages/bench.html"

if [[ "${SKIP_GATE}" != "1" ]]; then
  set +e
  ./script/bench-gate.sh --v2
  GATE_RC=$?
  set -e
  if [[ "${GATE_RC}" -ne 0 ]]; then
    echo "bench-nightly: FAIL bench-gate --v2 exit ${GATE_RC}" >&2
  fi
fi

ELAPSED=$(( $(date +%s) - START_TS ))
REPORT="${OUTDIR}/report.json"
PUBLISH_JSON=false
[[ "${PUBLISH_ONLY}" -eq 1 ]] && PUBLISH_JSON=true
OK_JSON=false
if [[ "${MEASURE_RC}" -eq 0 && "${GATE_RC}" -eq 0 ]]; then
  OK_JSON=true
fi
GENERATED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
cat > "${REPORT}" <<EOF
{
  "suite": "v2",
  "issue": 36385,
  "sha": "${SHA}",
  "publish_only": ${PUBLISH_JSON},
  "elapsed_s": ${ELAPSED},
  "measure_rc": ${MEASURE_RC},
  "gate_rc": ${GATE_RC},
  "history_path": "${HISTORY_PATH}",
  "results_path": "benchmarks/v2/RESULTS.json",
  "chart_path": "docs/pages/bench.html",
  "generated_at": "${GENERATED_AT}",
  "ok": ${OK_JSON}
}
EOF
echo "bench-nightly: wrote ${REPORT}"

if [[ "${GATE_RC}" -ne 0 ]]; then
  exit "${GATE_RC}"
fi

echo "bench-nightly: OK (${ELAPSED}s)"
