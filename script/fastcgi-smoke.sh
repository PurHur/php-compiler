#!/usr/bin/env bash
# FastCGI record + TCP adapter PHPUnit smoke (issues #173, #1899).
#
# Same as: FASTCGI_SMOKE_GATE=1 ./script/ci-local.sh --filter 'FastCgiRecordTest|FastCgiTest'
#
# Usage:
#   ./script/fastcgi-smoke.sh
#   make fastcgi-smoke
#
# Docker:
#   ./script/docker-exec.sh -- make fastcgi-smoke
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if [[ "${FASTCGI_SMOKE_GATE:-1}" != "1" ]]; then
  echo "fastcgi-smoke: skip (FASTCGI_SMOKE_GATE=0 — set 1 to run #173 adapter tests)" >&2
  exit 0
fi
if [[ -n "${PHP_COMPILER_SKIP_SERVE_TESTS:-}" ]]; then
  echo "fastcgi-smoke: skip (PHP_COMPILER_SKIP_SERVE_TESTS is set)" >&2
  exit 0
fi

# shellcheck source=script/php-env.sh
source "$ROOT/script/php-env.sh"

# VM adapter only — AOT FastCGI execute stays on FASTCGI_WEB_AOT_SMOKE_GATE (#2352).
export FASTCGI_WEB_AOT_SMOKE_GATE=0

echo "fastcgi-smoke: PHPUnit FastCgiRecordTest|FastCgiTest (FASTCGI_SMOKE_GATE=1, #173, #1899)..."
exec vendor/bin/phpunit --filter 'FastCgiRecordTest|FastCgiTest' "$@"
