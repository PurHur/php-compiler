#!/usr/bin/env bash
# Harness-safe PHPUnit — always runs inside php-compiler:22.04-dev with cgroup + memory_limit caps.
#
# Usage:
#   ./script/phpunit.sh --filter FooTest
#   ./script/phpunit.sh test/unit/BarTest.php
set -euo pipefail
cd "$(dirname "$0")/.."
args=$(printf '%q ' "$@")
# Already inside php-compiler:22.04-dev (nested phpunit.sh would deadlock the workspace CI lock).
if [[ -f /.dockerenv ]] || [[ "${PHP_COMPILER_IN_DOCKER:-0}" == "1" ]]; then
  # shellcheck source=php-env.sh
  source script/php-env.sh
  eval "exec vendor/bin/phpunit ${args}"
fi
exec ./script/docker-exec.sh -- bash -lc "source script/php-env.sh && vendor/bin/phpunit ${args}"
