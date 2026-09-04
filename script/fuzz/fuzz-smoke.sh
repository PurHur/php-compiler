#!/usr/bin/env bash
# Quick differential-fuzz smoke: generate+VM-compare a fixed seed window (#36398).
# Re-execs via docker-exec when not already in the CI image.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

COUNT="${FUZZ_SMOKE_COUNT:-30}"
SEED_BASE="${FUZZ_SMOKE_SEED_BASE:-1}"

if ! { [[ -f /.dockerenv ]] && [[ -f /opt/llvm9/libLLVM-9.so.1 ]]; } \
    && [[ "${PHP_COMPILER_IN_DOCKER:-0}" != "1" ]]; then
  exec ./script/docker-exec.sh -- bash -lc \
    "source script/php-env.sh && FUZZ_SMOKE_COUNT=${COUNT} FUZZ_SMOKE_SEED_BASE=${SEED_BASE} ./script/fuzz/fuzz-smoke.sh"
fi

# shellcheck source=php-env.sh
source script/php-env.sh

echo "fuzz-smoke: generating+comparing ${COUNT} programs (seed-base=${SEED_BASE}, backend=vm)..."
php script/fuzz/run.php --count "$COUNT" --seed-base "$SEED_BASE" --backend vm --quiet \
  --outdir "build/fuzz-smoke-$$" --keep-failures "build/fuzz-smoke-fail-$$"
echo "fuzz-smoke: seed corpus differential..."
./script/differential-sweep.sh --dir test/differential/cases/fuzz
echo "fuzz-smoke: OK"
