#!/usr/bin/env bash
# Size budgets + ratchet (#36403).
#
# Fails when a tracked file grows past its committed budget. Budgets only move
# down (ratchet): after a shrink, update script/size-budgets.json to the new
# count so the next PR cannot re-grow the file.
#
# Targets (Done-when for #36403): Compiler.php and JIT.php ≤ 25000 each;
# ratchet 2k per release toward Compiler 20k / JIT 20k / VM 15k.
#
# Usage:
#   ./script/check-size-budgets.sh           # check
#   ./script/check-size-budgets.sh --print   # show live counts vs budgets
set -euo pipefail
cd "$(dirname "$0")/.."

PHP_BIN="${PHP_BIN:-php}"
exec "$PHP_BIN" script/check-size-budgets.php "$@"
