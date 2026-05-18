#!/usr/bin/env bash
# Apply local patches to vendored dependencies (php-llvm PHP 8.2 / LLVM path fixes).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PATCH_DIR="$ROOT/patches"
VENDOR_LLVM="$ROOT/vendor/ircmaxell/php-llvm"

if [[ ! -d "$VENDOR_LLVM" ]]; then
  echo "vendor/ircmaxell/php-llvm not found; run composer install first" >&2
  exit 1
fi

apply_patch() {
  local patch="$1"
  if [[ ! -f "$patch" ]]; then
    return 0
  fi
  if git -C "$ROOT" apply --check -p0 "$patch" 2>/dev/null; then
    git -C "$ROOT" apply -p0 "$patch"
    echo "Applied $(basename "$patch")"
  else
    echo "Skip $(basename "$patch") (already applied or failed)"
  fi
}

apply_patch "$PATCH_DIR/php-llvm-chooser.patch"
apply_patch "$PATCH_DIR/php-llvm-context-empty-arrays.patch"
apply_patch "$PATCH_DIR/php-llvm-makearray-empty.patch"
apply_patch "$PATCH_DIR/php-llvm-builder-select.patch"
apply_patch "$PATCH_DIR/php-llvm-phi-add-incoming.patch"
apply_patch "$PATCH_DIR/php-llvm-builder-and-or.patch"
apply_patch "$PATCH_DIR/php-llvm-x86-posix-fallback.patch"

if [[ -d "$ROOT/vendor/ircmaxell/php-types" ]]; then
  apply_patch "$PATCH_DIR/php-types-binaryop-pow.patch"
  apply_patch "$PATCH_DIR/php-types-binaryop-spaceship.patch"
  apply_patch "$PATCH_DIR/php-types-str-bool-fns.patch"
  apply_patch "$PATCH_DIR/php-types-dollars-brace.patch"
fi

if [[ -d "$ROOT/vendor/ircmaxell/php-cfg" ]]; then
  apply_patch "$PATCH_DIR/php-cfg-dollars-brace.patch"
fi

if [[ -d "$ROOT/vendor/pre/plugin" ]]; then
  apply_patch "$PATCH_DIR/pre-plugin-parser-macros.patch"
fi
