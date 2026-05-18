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
  for ext in tokenizer mbstring dom xml xmlwriter ffi posix; do
    if [[ -f "$EXT_DIR/${ext}.so" ]]; then
      PHP_OPTS+=(-d "extension=$EXT_DIR/${ext}.so")
    fi
  done
fi

if command -v composer >/dev/null 2>&1 && composer --version >/dev/null 2>&1; then
  COMPOSER=(composer)
elif [[ -f /tmp/composer.phar ]]; then
  COMPOSER=("$PHP_BIN" -d "extension=$EXT_DIR/phar.so" -d "extension=$EXT_DIR/mbstring.so" /tmp/composer.phar)
else
  python3 -c "import urllib.request; urllib.request.urlretrieve('https://getcomposer.org/download/latest-stable/composer.phar','/tmp/composer.phar')"
  COMPOSER=("$PHP_BIN" -d "extension=$EXT_DIR/phar.so" -d "extension=$EXT_DIR/mbstring.so" /tmp/composer.phar)
fi
"${COMPOSER[@]}" install --no-interaction --ignore-platform-reqs --no-plugins 2>/dev/null || true

chmod +x script/install-llvm9.sh script/apply-patches.sh 2>/dev/null || true
if [[ -x script/install-llvm9.sh ]]; then
  script/install-llvm9.sh || true
fi
if [[ -x script/apply-patches.sh ]]; then
  script/apply-patches.sh || true
fi

"$PHP_BIN" "${PHP_OPTS[@]}" vendor/bin/phpunit "$@"
