#!/usr/bin/env bash
# Run PHP with host extensions and LLVM env (same as ci-local.sh).
set -euo pipefail
cd "$(dirname "$0")/.."
# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
exec "$PHP_BIN" "${PHP_OPTS[@]}" "$@"
