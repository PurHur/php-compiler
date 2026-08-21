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
# Require image LLVM — bare /.dockerenv is also set on RunForge harness hosts, where host
# AOT binaries SIGSEGV (php-env.sh uses the same /opt/llvm9 check).
if { [[ -f /.dockerenv ]] && [[ -f /opt/llvm9/libLLVM-9.so.1 ]]; } || [[ "${PHP_COMPILER_IN_DOCKER:-0}" == "1" ]]; then
  # shellcheck source=php-env.sh
  source script/php-env.sh
  eval "exec vendor/bin/phpunit ${args}"
fi
exec ./script/docker-exec.sh -- bash -lc "source script/php-env.sh && vendor/bin/phpunit ${args}"
