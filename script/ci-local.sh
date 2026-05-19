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
"${COMPOSER[@]}" install --no-interaction --ignore-platform-reqs 2>/dev/null || true

chmod +x script/install-llvm9.sh script/apply-patches.sh 2>/dev/null || true
if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  if [[ -x script/install-llvm9.sh ]]; then
    script/install-llvm9.sh || true
  fi
fi
if [[ -x script/apply-patches.sh ]]; then
  script/apply-patches.sh || true
fi

"$PHP_BIN" "${PHP_OPTS[@]}" script/capability-matrix.php --check
"$PHP_BIN" "${PHP_OPTS[@]}" script/bootstrap-inventory.php --check

LLVM_DIR="${PHP_COMPILER_LLVM_PATH:-$(cd "$(dirname "$0")/.." && pwd)/.llvm}"
if [[ -f "$LLVM_DIR/libLLVM-9.so.1" ]]; then
  echo "LLVM 9 found at $LLVM_DIR: JIT compliance, AOT fixtures (simple_web_*, static_web), and ExampleWebAotTest will run."
else
  echo "LLVM 9 missing: @group llvm tests (JIT, AOT, web AOT) are skipped. Run: script/install-llvm9.sh"
fi

# HTTP serve integration tests (ServeTest, ServeAotTest) need loopback TCP.
# GitHub Actions sets PHP_COMPILER_SKIP_SERVE_TESTS=1; local/Docker CI must not.
can_bind_loopback() {
  "$PHP_BIN" "${PHP_OPTS[@]}" script/can-bind-loopback.php
}

configure_serve_tests() {
  if [[ -n "${PHP_COMPILER_SKIP_SERVE_TESTS:-}" ]]; then
    echo "HTTP serve integration tests skipped (PHP_COMPILER_SKIP_SERVE_TESTS is set)."
    return
  fi
  if [[ "${PHP_COMPILER_RUN_SERVE_TESTS:-}" == "1" ]]; then
    echo "HTTP serve integration tests forced (PHP_COMPILER_RUN_SERVE_TESTS=1)."
    return
  fi
  if can_bind_loopback; then
    echo "Loopback TCP bind OK: ServeTest and ServeAotTest will run."
    return
  fi
  export PHP_COMPILER_SKIP_SERVE_TESTS=1
  echo "Cannot bind 127.0.0.1 — skipping @group serve tests."
  echo "  Set PHP_COMPILER_RUN_SERVE_TESTS=1 to force, or PHP_COMPILER_SKIP_SERVE_TESTS=1 to silence."
}

configure_serve_tests

echo "PHPUnit: VM, compliance (no LLVM), real-world (includes ExamplesCompileTest VM lint/smoke)..."
"$PHP_BIN" "${PHP_OPTS[@]}" vendor/bin/phpunit --exclude-group llvm,serve "$@"

if [[ -z "${PHP_COMPILER_SKIP_SERVE_TESTS:-}" ]]; then
  echo "PHPUnit: HTTP serve (bin/serve.php, phpc serve --aot)..."
  "$PHP_BIN" "${PHP_OPTS[@]}" vendor/bin/phpunit --group serve "$@"
fi

if [[ -f "$LLVM_DIR/libLLVM-9.so.1" ]]; then
  echo "PHPUnit: JIT, AOT (web fixtures + examples, ExamplesCompileTest AOT lint)..."
  LLVM_JUNIT="$(mktemp "${TMPDIR:-/tmp}/llvm-junit.XXXXXX.xml")"
  "$PHP_BIN" "${PHP_OPTS[@]}" vendor/bin/phpunit --group llvm --exclude-group serve --log-junit "$LLVM_JUNIT" "$@"
  if [[ -n "${PHP_COMPILER_ALLOW_JIT_SKIP:-}" ]]; then
    echo "JIT compliance guard skipped (PHP_COMPILER_ALLOW_JIT_SKIP is set)."
  else
    "$PHP_BIN" "${PHP_OPTS[@]}" script/check-jit-compliance-ran.php "$LLVM_JUNIT" "$LLVM_DIR"
  fi
  rm -f "$LLVM_JUNIT"
fi
