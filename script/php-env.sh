# Shared host PHP / LLVM environment (source from bash scripts).
PHP_BIN="${PHP_COMPILER_PHP:-}"
if [[ -z "$PHP_BIN" ]]; then
  for candidate in php8.2 php8.1 php; do
    if command -v "$candidate" >/dev/null 2>&1; then
      PHP_BIN="$candidate"
      break
    fi
  done
fi
if [[ -z "$PHP_BIN" ]]; then
  PHP_BIN=php
fi
if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
  for candidate in php8.2 php8.1 php; do
    if command -v "$candidate" >/dev/null 2>&1; then
      PHP_BIN="$candidate"
      break
    fi
  done
fi
export PHP_COMPILER_EXT_DIR="${PHP_COMPILER_EXT_DIR:-/usr/lib/php/20220829}"
LLVM_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/.llvm"
if [[ -f "$LLVM_DIR/libLLVM-9.so.1" ]]; then
  export LD_LIBRARY_PATH="$LLVM_DIR${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"
  export PATH="$LLVM_DIR${PATH:+:$PATH}"
  export PHP_COMPILER_LLVM_PATH="$LLVM_DIR"
fi
EXT_DIR="$PHP_COMPILER_EXT_DIR"
PHP_OPTS=()
if [[ -d "$EXT_DIR" ]]; then
  for ext in tokenizer mbstring dom xml xmlwriter ffi posix phar; do
    if [[ -f "$EXT_DIR/${ext}.so" ]]; then
      PHP_OPTS+=(-d "extension=$EXT_DIR/${ext}.so")
    fi
  done
fi
