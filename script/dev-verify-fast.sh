#!/usr/bin/env bash
# Sub-minute dev feedback loop: AOT toolchain smoke + VM differential tier-0.
#
# Use while iterating on lowering / builtins. Before merge, run:
#   make north-star5-verify-fast     (~4 min, M5 daily gate)
#   script/differential-sweep.sh --aot --repeat 3   (full AOT corpus)
#
# Optional: DEV_VERIFY_AOT=1 adds full AOT differential tier-0 (~10 min).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

start=$(date +%s)
echo "dev-verify-fast: tier 0 — bootstrap trust preflight (non-blocking)..."
./script/bootstrap-trust-preflight.sh

echo "dev-verify-fast: tier 1 — aot-smoke (8 programs)..."
./script/aot-smoke.sh

echo "dev-verify-fast: tier 2 — VM differential tier-0 ($(find test/differential/tier0-fast -name '*.php' | wc -l) programs)..."
./script/differential-sweep.sh --dir test/differential/tier0-fast

if [[ "${DEV_VERIFY_AOT:-0}" == "1" ]]; then
  echo "dev-verify-fast: tier 3 — AOT differential tier-0 (DEV_VERIFY_AOT=1)..."
  ./script/differential-sweep.sh --aot --dir test/differential/tier0-fast --repeat 3
fi

elapsed=$(( $(date +%s) - start ))
echo "dev-verify-fast: OK (${elapsed}s wall — use north-star5-verify-fast before merge)"
