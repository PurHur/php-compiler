#!/usr/bin/env bash
# Local CI baseline: install deps and run the full PHPUnit suite (no Docker).
set -euo pipefail
cd "$(dirname "$0")/.."
# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
EXT_DIR="$PHP_COMPILER_EXT_DIR"

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

"$PHP_BIN" "${PHP_OPTS[@]}" script/capability-matrix.php --check

LLVM_DIR="$(cd "$(dirname "$0")/.." && pwd)/.llvm"
if [[ -f "$LLVM_DIR/libLLVM-9.so.1" ]]; then
  echo "LLVM 9 found: JIT compliance, AOT fixtures (simple_web_*, static_web), and ExampleWebAotTest will run."
else
  echo "LLVM 9 missing: @group llvm tests (JIT, AOT, web AOT) are skipped. Run: script/install-llvm9.sh"
fi

# Full suite includes test/real/ServeTest.php (bin/serve.php HTTP). Set
# PHP_COMPILER_SKIP_SERVE_TESTS=1 in sandboxes that cannot bind TCP ports.
echo "PHPUnit: VM, compliance (no LLVM), real-world (includes ExamplesCompileTest VM lint/smoke)..."
"$PHP_BIN" "${PHP_OPTS[@]}" vendor/bin/phpunit --exclude-group llvm "$@"

if [[ -f "$LLVM_DIR/libLLVM-9.so.1" ]]; then
  echo "PHPUnit: JIT, AOT (web fixtures + examples, ExamplesCompileTest AOT lint)..."
  "$PHP_BIN" "${PHP_OPTS[@]}" vendor/bin/phpunit --group llvm "$@"
fi
