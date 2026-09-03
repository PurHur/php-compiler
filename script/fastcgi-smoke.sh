#!/usr/bin/env bash
# FastCGI record + TCP adapter PHPUnit smoke (issues #173, #1899).
# Optional --soak N: RSS flatness gate for long-lived VM worker (#36388).
#
# Same as: FASTCGI_SMOKE_GATE=1 ./script/ci-local.sh --filter 'FastCgiRecordTest|FastCgiTest'
#
# Usage:
#   ./script/fastcgi-smoke.sh
#   ./script/fastcgi-smoke.sh --soak 100
#   make fastcgi-smoke
#
# Docker:
#   ./script/docker-exec.sh -- make fastcgi-smoke
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

SOAK_N=0
PHPUNIT_ARGS=()
while [[ $# -gt 0 ]]; do
  case "$1" in
    --soak)
      SOAK_N="${2:-}"
      if [[ -z "$SOAK_N" || ! "$SOAK_N" =~ ^[0-9]+$ || "$SOAK_N" -lt 1 ]]; then
        echo "fastcgi-smoke: --soak requires a positive integer" >&2
        exit 2
      fi
      shift 2
      ;;
    --soak=*)
      SOAK_N="${1#--soak=}"
      if [[ -z "$SOAK_N" || ! "$SOAK_N" =~ ^[0-9]+$ || "$SOAK_N" -lt 1 ]]; then
        echo "fastcgi-smoke: --soak requires a positive integer" >&2
        exit 2
      fi
      shift
      ;;
    *)
      PHPUNIT_ARGS+=("$1")
      shift
      ;;
  esac
done

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
vendor/bin/phpunit --filter 'FastCgiRecordTest|FastCgiTest' "${PHPUNIT_ARGS[@]+"${PHPUNIT_ARGS[@]}"}"

if [[ "$SOAK_N" -gt 0 ]]; then
  echo "fastcgi-smoke: soak ${SOAK_N} VM FastCGI requests (RSS flatness, #36388)..."
  php "$ROOT/script/fastcgi-soak.php" --requests="$SOAK_N"
fi
