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

patch_already_applied() {
  local patch="$1"
  case "$(basename "$patch")" in
    php-types-binaryop-coalesce.patch)
      grep -q "case 'Expr_BinaryOp_Coalesce':" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php" 2>/dev/null
      ;;
    php-types-nullsafe.patch)
      grep -q "case 'Expr_NullsafePropertyFetch':" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php" 2>/dev/null
      ;;
    php-types-nullable-return.patch)
      grep -q 'CfgType\\Nullable' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null
      ;;
    php-types-cfg-reference.patch)
      grep -q 'instanceof CfgType\\Reference' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null
      ;;
    php-types-nullable-optype-return.patch)
      grep -A2 'instanceof Op\\Type\\Nullable' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php" 2>/dev/null \
        | grep -q 'return (new Type(Type::TYPE_UNION'
      ;;
    php-types-fromvalue-null.patch)
      grep -q 'is_null($value)' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null
      ;;
    php-types-doc-comment-string.patch)
      grep -q 'instanceof \\PhpParser\\Comment' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null
      ;;
    php-types-array-shape.patch)
      grep -q "preg_match('/^array\\{/'" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null
      ;;
    php-types-generics-fallback.patch)
      grep -q "non-empty-string" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null
      ;;
    php-types-generics-list-array.patch)
      grep -q "preg_match('/^(list|array)\\s*</i'" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null
      ;;
    php-types-ns-func-call.patch)
      grep -q 'function resolveOp_Expr_NsFuncCall' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php" 2>/dev/null
      ;;
    php-types-str-split-string-array.patch)
      grep -q "'str_split' => \['string\[\]'" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-types-readfile-int-false.patch)
      grep -q "'readfile' => \['int|false'" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-types-strpbrk-string-false.patch)
      grep -q "'strpbrk' => \['string|false'" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-types-crc32-int.patch)
      grep -q "'crc32' => \['int'" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-llvm-builder-xor.patch)
      grep -q 'function xor(' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/Builder.php" 2>/dev/null
      ;;
    php-llvm-memory-buffer-bitcode.patch)
      grep -q 'use llvm\\string_ptr;' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/MemoryBuffer.php" 2>/dev/null
      ;;
    php-cfg-nullsafe.patch)
      [[ -f "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/NullsafePropertyFetch.php" ]]
      ;;
    php-cfg-nullsafe-parser.patch)
      grep -q 'function parseExpr_NullsafePropertyFetch' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-strict-types.patch)
      grep -q 'public \$strictTypes' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Func.php" 2>/dev/null
      ;;
    php-cfg-trycatch.patch)
      [[ -f "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/TryCatch.php" ]] \
        && grep -q 'new Op\\Stmt\\TryCatch' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-magic-constants.patch)
      grep -q 'namespaceStack' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/AstVisitor/MagicStringResolver.php" 2>/dev/null
      ;;
    php-cfg-magic-script-const.patch)
      grep -q 'KIND_LINE' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/MagicScriptConst.php" 2>/dev/null
      ;;
    php-cfg-magic-line.patch)
      ! grep -q 'MagicConst\\Line' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/AstVisitor/MagicStringResolver.php" 2>/dev/null
      ;;
    php-cfg-switch-cond-property.patch)
      grep -q 'public \$cond;' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/Switch_.php" 2>/dev/null
      ;;
    php-cfg-match.patch)
      grep -q 'function parseExpr_Match' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-assignop-coalesce.patch)
      grep -q "'Expr_AssignOp_Coalesce'" "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-first-class-callable.patch)
      grep -q 'isFirstClassCallable' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-anonymous-class.patch)
      grep -q 'parseStmt_Class($expr->class)' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-enum.patch)
      grep -q 'parseStmt_Enum' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-named-args.patch)
      grep -q 'callArgName' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-spread.patch)
      grep -q 'callArgUnpack' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-never-type.patch)
      [[ -f "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Type/Never_.php" ]]
      ;;
    php-cfg-intersection-type.patch)
      [[ -f "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Type/Intersection.php" ]]
      ;;
    php-types-never-type.patch)
      grep -q 'Op\\Type\\Never_' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php" 2>/dev/null
      ;;
    php-types-intersection-type.patch)
      grep -q 'instanceof CfgType\\Intersection' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null
      ;;
    php-types-magic-script-const.patch)
      grep -q 'KIND_LINE === \$op->kind' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php" 2>/dev/null
      ;;
    php-types-first-class-callable.patch)
      grep -q 'Expr_FirstClassCallable' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php" 2>/dev/null
      ;;
    *)
      return 1
      ;;
  esac
}

apply_patch() {
  local patch="$1"
  if [[ ! -f "$patch" ]]; then
    return 0
  fi
  if patch_already_applied "$patch"; then
    echo "Skip $(basename "$patch") (already applied)"
    return 0
  fi
  if git -C "$ROOT" apply --check -p0 "$patch" 2>/dev/null; then
    git -C "$ROOT" apply -p0 "$patch"
    echo "Applied $(basename "$patch")"
  else
    echo "Skip $(basename "$patch") (already applied or failed)" >&2
    case "$(basename "$patch")" in
      php-cfg-strict-types.patch)
        echo "ERROR: php-cfg-strict-types.patch is required for AOT (declare(strict_types))." >&2
        return 1
        ;;
    esac
  fi
}

apply_patch "$PATCH_DIR/php-llvm-chooser.patch"
apply_patch "$PATCH_DIR/php-llvm-context-empty-arrays.patch"
apply_patch "$PATCH_DIR/php-llvm-makearray-empty.patch"
apply_patch "$PATCH_DIR/php-llvm-builder-select.patch"
apply_patch "$PATCH_DIR/php-llvm-value-addincoming.patch"
apply_patch "$PATCH_DIR/php-llvm-llvmabstract-value-addincoming.patch"
apply_patch "$PATCH_DIR/php-llvm-builder-and-or.patch"
apply_patch "$PATCH_DIR/php-llvm-builder-xor.patch"
apply_patch "$PATCH_DIR/php-llvm-memory-buffer-bitcode.patch"
apply_patch "$PATCH_DIR/php-llvm-x86-posix-fallback.patch"

# php-cfg before php-types: php-types-mixed-reserved.patch references Op\Type\Mixed_.
if [[ -d "$ROOT/vendor/ircmaxell/php-cfg" ]]; then
  apply_patch "$PATCH_DIR/php-cfg-dollars-brace.patch"
  apply_patch "$PATCH_DIR/php-cfg-mixed-reserved.patch"
  apply_patch "$PATCH_DIR/php-cfg-nullsafe.patch"
  apply_patch "$PATCH_DIR/php-cfg-nullsafe-parser.patch"
  apply_patch "$PATCH_DIR/php-cfg-strict-types.patch"
  apply_patch "$PATCH_DIR/php-cfg-trycatch.patch"
  apply_patch "$PATCH_DIR/php-cfg-magic-constants.patch"
  apply_patch "$PATCH_DIR/php-cfg-magic-script-const.patch"
  apply_patch "$PATCH_DIR/php-cfg-magic-line.patch"
  apply_patch "$PATCH_DIR/php-cfg-switch-cond-property.patch"
  apply_patch "$PATCH_DIR/php-cfg-match.patch"
  apply_patch "$PATCH_DIR/php-cfg-assignop-coalesce.patch"
  apply_patch "$PATCH_DIR/php-cfg-first-class-callable.patch"
  apply_patch "$PATCH_DIR/php-cfg-anonymous-class.patch"
  apply_patch "$PATCH_DIR/php-cfg-enum.patch"
  apply_patch "$PATCH_DIR/php-cfg-named-args.patch"
  apply_patch "$PATCH_DIR/php-cfg-spread.patch"
  apply_patch "$PATCH_DIR/php-cfg-never-type.patch"
  apply_patch "$PATCH_DIR/php-cfg-intersection-type.patch"
fi

if [[ -d "$ROOT/vendor/ircmaxell/php-types" ]]; then
  apply_patch "$PATCH_DIR/php-types-binaryop-pow.patch"
  apply_patch "$PATCH_DIR/php-types-binaryop-coalesce.patch"
  apply_patch "$PATCH_DIR/php-types-binaryop-spaceship.patch"
  apply_patch "$PATCH_DIR/php-types-str-bool-fns.patch"
  apply_patch "$PATCH_DIR/php-types-str-split-string-array.patch"
  apply_patch "$PATCH_DIR/php-types-readfile-int-false.patch"
  apply_patch "$PATCH_DIR/php-types-strpbrk-string-false.patch"
  apply_patch "$PATCH_DIR/php-types-crc32-int.patch"
  apply_patch "$PATCH_DIR/php-types-dollars-brace.patch"
  apply_patch "$PATCH_DIR/php-types-mixed-reserved.patch"
  apply_patch "$PATCH_DIR/php-types-nullsafe.patch"
  apply_patch "$PATCH_DIR/php-types-nullable-return.patch"
  apply_patch "$PATCH_DIR/php-types-cfg-reference.patch"
  apply_patch "$PATCH_DIR/php-types-nullable-optype-return.patch"
  apply_patch "$PATCH_DIR/php-types-fromvalue-null.patch"
  apply_patch "$PATCH_DIR/php-types-doc-comment-string.patch"
  apply_patch "$PATCH_DIR/php-types-array-shape.patch"
  apply_patch "$PATCH_DIR/php-types-generics-fallback.patch"
  apply_patch "$PATCH_DIR/php-types-generics-list-array.patch"
  apply_patch "$PATCH_DIR/php-types-ns-func-call.patch"
  apply_patch "$PATCH_DIR/php-types-magic-script-const.patch"
  apply_patch "$PATCH_DIR/php-types-first-class-callable.patch"
  apply_patch "$PATCH_DIR/php-types-never-type.patch"
  apply_patch "$PATCH_DIR/php-types-intersection-type.patch"
fi

if [[ -d "$ROOT/vendor/pre/plugin" ]]; then
  apply_patch "$PATCH_DIR/pre-plugin-parser-macros.patch"
fi
