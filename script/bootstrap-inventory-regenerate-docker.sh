#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

# Harness-safe regeneration: updates host working tree even when docker-exec uses tar fallback.
./script/docker-exec.sh --sync-back docs/bootstrap-inventory.md -- php script/bootstrap-inventory.php
