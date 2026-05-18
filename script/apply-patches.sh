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
  local file="$1"
  local patch="$2"
  if [[ ! -f "$patch" ]]; then
    return 0
  fi
  if patch --forward -p0 -d "$ROOT" -i "$patch" 2>/dev/null; then
    echo "Applied $(basename "$patch")"
  else
    echo "Skip $(basename "$patch") (already applied or failed)"
  fi
}

apply_patch "$VENDOR_LLVM/lib/Chooser.php" "$PATCH_DIR/php-llvm-chooser.patch"
apply_patch "$VENDOR_LLVM/lib/LLVMAbstract/Context.php" "$PATCH_DIR/php-llvm-context-empty-arrays.patch"
apply_patch "$VENDOR_LLVM/ffi/llvm9.php" "$PATCH_DIR/php-llvm-makearray-empty.patch"
apply_patch "$VENDOR_LLVM/lib/LLVMAbstract/Builder.php" "$PATCH_DIR/php-llvm-builder-select.patch"
apply_patch "$VENDOR_LLVM/lib/LLVMAbstract/Value/Instruction.php" "$PATCH_DIR/php-llvm-phi-add-incoming.patch"
apply_patch "$VENDOR_LLVM/lib/LLVMAbstract/Builder.php" "$PATCH_DIR/php-llvm-builder-and-or.patch"
apply_patch "$VENDOR_LLVM/lib/LLVMAbstract/TargetSet/X86.php" "$PATCH_DIR/php-llvm-x86-posix-fallback.patch"

VENDOR_TYPES="$ROOT/vendor/ircmaxell/php-types"
if [[ -d "$VENDOR_TYPES" ]]; then
  apply_patch "$VENDOR_TYPES/lib/PHPTypes/TypeReconstructor.php" "$PATCH_DIR/php-types-binaryop-pow.patch"
fi
