# Shared host PHP / LLVM environment (source from bash scripts).
PHP_BIN="${PHP_COMPILER_PHP:-}"
if [[ -z "$PHP_BIN" ]]; then
  for candidate in php8.2 php8.1 php; do
    if resolved="$(command -v "$candidate" 2>/dev/null)" && [[ -n "$resolved" && -x "$resolved" && ! -d "$resolved" ]]; then
      PHP_BIN="$resolved"
      break
    fi
  done
fi
if [[ -z "$PHP_BIN" ]]; then
  PHP_BIN=php
fi
if ! resolved="$(command -v "$PHP_BIN" 2>/dev/null)" || [[ -z "$resolved" || ! -x "$resolved" || -d "$resolved" ]]; then
  for candidate in php8.2 php8.1 php; do
    if resolved="$(command -v "$candidate" 2>/dev/null)" && [[ -n "$resolved" && -x "$resolved" && ! -d "$resolved" ]]; then
      PHP_BIN="$resolved"
      break
    fi
  done
fi
export PHP_COMPILER_EXT_DIR="${PHP_COMPILER_EXT_DIR:-/usr/lib/php/20220829}"
_REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
_REPO_LLVM="$_REPO_ROOT/.llvm"
_LLVM_DIR=""
if [[ -f "$_REPO_LLVM/libLLVM-9.so.1" ]]; then
  _LLVM_DIR="$_REPO_LLVM"
elif [[ -n "${PHP_COMPILER_LLVM_PATH:-}" && -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  _LLVM_DIR="$PHP_COMPILER_LLVM_PATH"
elif [[ -f /opt/llvm9/libLLVM-9.so.1 ]]; then
  _LLVM_DIR=/opt/llvm9
fi
if [[ -n "$_LLVM_DIR" ]]; then
  export LD_LIBRARY_PATH="$_LLVM_DIR${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"
  export PATH="$_LLVM_DIR${PATH:+:$PATH}"
  export PHP_COMPILER_LLVM_PATH="$_LLVM_DIR"
fi
unset _REPO_ROOT _REPO_LLVM _LLVM_DIR
EXT_DIR="$PHP_COMPILER_EXT_DIR"
PHP_OPTS=()
# Docker dev images often preload extensions; loading .so again breaks LLVM FFI (#764).
_PHP_LOADED_MODULES=$("$PHP_BIN" -m 2>/dev/null || true)
if [[ -d "$EXT_DIR" ]]; then
  for ext in tokenizer mbstring dom xml xmlwriter ffi posix phar; do
    if [[ -f "$EXT_DIR/${ext}.so" ]] && ! grep -qxi "^${ext}$" <<< "$_PHP_LOADED_MODULES"; then
      PHP_OPTS+=(-d "extension=$EXT_DIR/${ext}.so")
    fi
  done
fi
unset _PHP_LOADED_MODULES
if [[ -f "$(dirname "${BASH_SOURCE[0]}")/ci-memory-env.sh" ]]; then
  # shellcheck source=ci-memory-env.sh
  source "$(dirname "${BASH_SOURCE[0]}")/ci-memory-env.sh"
fi
