#!/usr/bin/env bash
# Local CI baseline: install deps and run the full PHPUnit suite (no Docker).
set -euo pipefail
cd "$(dirname "$0")/.."
PHP_BIN="${PHP_COMPILER_PHP:-php}"
if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
  PHP_BIN="php8.2"
fi
export PHP_COMPILER_EXT_DIR="${PHP_COMPILER_EXT_DIR:-/usr/lib/php/20220829}"
EXT_DIR="$PHP_COMPILER_EXT_DIR"
PHP_OPTS=()
if [[ -d "$EXT_DIR" ]]; then
  for ext in tokenizer mbstring dom xml xmlwriter ffi; do
    if [[ -f "$EXT_DIR/${ext}.so" ]]; then
      PHP_OPTS+=(-d "extension=$EXT_DIR/${ext}.so")
    fi
  done
fi
if command -v composer >/dev/null 2>&1; then
  composer install --no-interaction --ignore-platform-reqs --no-plugins
fi
"$PHP_BIN" "${PHP_OPTS[@]}" vendor/bin/phpunit "$@"
