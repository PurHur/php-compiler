#!/usr/bin/env bash
# Harness-safe PHPUnit — always runs inside php-compiler:22.04-dev with cgroup + memory_limit caps.
#
# Usage:
#   ./script/phpunit.sh --filter FooTest
#   ./script/phpunit.sh test/unit/BarTest.php
set -euo pipefail
cd "$(dirname "$0")/.."
args=$(printf '%q ' "$@")
exec ./script/docker-exec.sh -- bash -lc "source script/php-env.sh && vendor/bin/phpunit ${args}"
