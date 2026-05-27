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
    php-types-static-var.patch)
      grep -q "case 'Terminal_StaticVar':" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php" 2>/dev/null
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
    php-types-docblock-first-token.patch)
      grep -q "(@return\\\\s+\\\\(\\[\\^\\\\s\\*\\]\\[\\^\\\\s\\]\\*\\\\)" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null \
        && grep -q "(@var\\\\s+\\\\(\\[\\^\\\\s\\*\\]\\[\\^\\\\s\\]\\*\\\\)" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null
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
    php-types-docblock-trailing-text.patch)
      grep -q "stripTrailingDocText" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null
      ;;
    php-types-ns-func-call.patch)
      grep -q 'function resolveOp_Expr_NsFuncCall' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php" 2>/dev/null
      ;;
    php-types-arrow-function.patch)
      grep -q 'function resolveOp_Expr_ArrowFunction' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php" 2>/dev/null
      ;;
    php-types-str-split-string-array.patch)
      grep -q "'str_split' => \['string\[\]'" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-types-readfile-int-false.patch)
      grep -q "'readfile' => \['int|false'" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-types-stream-context-array-return.patch)
      grep -q "'stream_context_create' => \['array'" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
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
      grep -q 'use llvm\\string_ptr;' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/MemoryBuffer.php" 2>/dev/null \
        && grep -q '\$this->llvm->lib->getFFI()' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/MemoryBuffer.php" 2>/dev/null
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
    php-cfg-phi-resolver-null.patch)
      grep -q 'null === \$phi->result' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Visitor/PhiResolver.php" 2>/dev/null
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
    php-cfg-no-arrow-function.patch)
      ! grep -q 'fn (Op\\Type $t) => ' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Printer.php" 2>/dev/null
      ;;
    php-cfg-no-closure-preg-replace-callback.patch)
      grep -q "repairCommentsCallback" "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/AstVisitor/MagicStringResolver.php" 2>/dev/null \
        && grep -q "docCommentTypeCallback" "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/AstVisitor/NameResolver.php" 2>/dev/null
      ;;
    php-cfg-property-type.patch)
      grep -q 'public \\$type;' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/Property.php" 2>/dev/null \
        && grep -q 'function __construct(Operand \\$name, int \\$visibility' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/Property.php" 2>/dev/null
      ;;
    php-cfg-assertion-expr-property.patch)
      grep -q 'public \\$expr;' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Assertion.php" 2>/dev/null
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
    php-cfg-arrow-function.patch)
      grep -q 'function parseExpr_ArrowFunction' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
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
    php-cfg-union-type.patch)
      [[ -f "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Type/Union_.php" ]]
      ;;
    php-cfg-ctor-promotion.patch)
      grep -q 'promotionFlags' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Param.php" 2>/dev/null
      ;;
    php-cfg-attribute-groups.patch)
      grep -q "attrGroups' = \$expr->attrGroups" "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-trait-use.patch)
      [[ -f "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/TraitUse.php" ]]
      ;;
    php-types-never-type.patch)
      grep -q 'Op\\Type\\Never_' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php" 2>/dev/null
      ;;
    php-types-intersection-type.patch)
      grep -q 'instanceof CfgType\\Intersection' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null
      ;;
    php-types-union-type.patch)
      grep -q 'instanceof CfgType\\Union_' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null \
        && grep -q 'instanceof Op\\Type\\Union_' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php" 2>/dev/null
      ;;
    php-types-magic-script-const.patch)
      grep -q 'KIND_LINE === \$op->kind' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php" 2>/dev/null
      ;;
    php-types-first-class-callable.patch)
      grep -q 'Expr_FirstClassCallable' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php" 2>/dev/null
      ;;
    php-types-missing-parent-no-echo.patch)
      ! grep -q "Could not find parent" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/State.php" 2>/dev/null
      ;;
    *)
      return 1
      ;;
  esac
}

apply_php_llvm_memory_buffer_overlay() {
  local target="$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/MemoryBuffer.php"
  local overlay="$PATCH_DIR/overlays/php-llvm/MemoryBuffer.php"
  if patch_already_applied "$PATCH_DIR/php-llvm-memory-buffer-bitcode.patch"; then
    echo "Skip php-llvm-memory-buffer-bitcode.patch (already applied)"
    return 0
  fi
  if [[ ! -f "$overlay" ]]; then
    echo "Skip php-llvm-memory-buffer-bitcode.patch (overlay missing)" >&2
    return 1
  fi
  cp "$overlay" "$target"
  echo "Applied php-llvm-memory-buffer-bitcode.patch (overlay)"
}

apply_php_cfg_match_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local overlay="$ROOT/patches/overlays/php-cfg/match-parser-methods.php"
  if grep -q 'function parseExpr_Match' "$parser" 2>/dev/null; then
    echo "Skip php-cfg-match.patch (already applied)"
    return 0
  fi
  if [[ ! -f "$overlay" ]]; then
    echo "Skip php-cfg-match.patch (overlay missing)" >&2
    return 1
  fi
  python3 - "$parser" "$overlay" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
overlay_path = Path(sys.argv[2])
text = parser_path.read_text()
anchor = "    protected function parseExpr_UnaryMinus(Expr\\UnaryMinus $expr)"
if anchor not in text:
    sys.stderr.write("php-cfg-match: parseExpr_UnaryMinus anchor not found in Parser.php\n")
    sys.exit(1)
insert = overlay_path.read_text().rstrip("\n") + "\n\n"
parser_path.write_text(text.replace(anchor, insert + anchor, 1))
PY
  echo "Applied php-cfg-match.patch (overlay)"
}

apply_php_cfg_arrow_function_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local op="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/ArrowFunction.php"
  local overlay="$PATCH_DIR/overlays/php-cfg"
  if grep -q 'function parseExpr_ArrowFunction' "$parser" 2>/dev/null; then
    echo "Skip php-cfg-arrow-function.patch (already applied)"
    return 0
  fi
  if [[ ! -f "$overlay/Op/Expr/ArrowFunction.php" || ! -f "$overlay/arrow-function-parser-method.php" ]]; then
    echo "Skip php-cfg-arrow-function.patch (overlay files missing)" >&2
    return 1
  fi
  mkdir -p "$(dirname "$op")"
  cp "$overlay/Op/Expr/ArrowFunction.php" "$op"
  python3 - "$parser" "$overlay/arrow-function-parser-method.php" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
method_path = Path(sys.argv[2])
text = parser_path.read_text()
anchor = """    protected function parseExpr_ClassConstFetch(Expr\\ClassConstFetch $expr)
    {
        $c = $this->readVariable($this->parseExprNode($expr->class));"""
insert = method_path.read_text().rstrip("\n") + "\n\n"
if anchor not in text:
    sys.stderr.write("php-cfg-arrow-function: parseExpr_ClassConstFetch anchor not found in Parser.php\n")
    sys.exit(1)
parser_path.write_text(text.replace(anchor, insert + anchor, 1))
PY
  echo "Applied php-cfg-arrow-function.patch (overlay)"
}

apply_php_cfg_trycatch_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local op="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/TryCatch.php"
  local overlay="$PATCH_DIR/overlays/php-cfg"
  if grep -q 'new Op\\Stmt\\TryCatch' "$parser" 2>/dev/null; then
    echo "Skip php-cfg-trycatch.patch (already applied)"
    return 0
  fi
  if [[ ! -f "$overlay/Op/Stmt/TryCatch.php" || ! -f "$overlay/trycatch-parser-method.php" ]]; then
    echo "Skip php-cfg-trycatch.patch (overlay files missing)" >&2
    return 1
  fi
  mkdir -p "$(dirname "$op")"
  cp "$overlay/Op/Stmt/TryCatch.php" "$op"
  python3 - "$parser" "$overlay/trycatch-parser-method.php" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
method_path = Path(sys.argv[2])
text = parser_path.read_text()
old = """    protected function parseStmt_TryCatch(Stmt\\TryCatch $node)
    {
        // TODO: implement this!!!
    }"""
new = method_path.read_text()
if old not in text:
    sys.stderr.write("php-cfg-trycatch: parseStmt_TryCatch stub not found in Parser.php\n")
    sys.exit(1)
parser_path.write_text(text.replace(old, new.rstrip("\n"), 1))
PY
  echo "Applied php-cfg-trycatch.patch (overlay)"
}

apply_patch() {
  local patch="$1"
  if [[ ! -f "$patch" ]]; then
    return 0
  fi
  if [[ "$(basename "$patch")" == "php-cfg-match.patch" ]]; then
    apply_php_cfg_match_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-arrow-function.patch" ]]; then
    apply_php_cfg_arrow_function_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-trycatch.patch" ]]; then
    apply_php_cfg_trycatch_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-llvm-memory-buffer-bitcode.patch" ]]; then
    apply_php_llvm_memory_buffer_overlay
    return $?
  fi
  if patch_already_applied "$patch"; then
    echo "Skip $(basename "$patch") (already applied)"
    return 0
  fi
  if git -C "$ROOT" apply --check -p0 "$patch" 2>/dev/null; then
    git -C "$ROOT" apply -p0 "$patch"
    echo "Applied $(basename "$patch")"
  elif patch -p0 --dry-run -s -f < "$patch" >/dev/null 2>&1; then
    patch -p0 -s -f < "$patch" >/dev/null 2>&1
    echo "Applied $(basename "$patch") (patch(1))"
  elif patch -p0 --reverse --dry-run -s -f < "$patch" >/dev/null 2>&1; then
    echo "Skip $(basename "$patch") (already applied)"
  else
    echo "Skip $(basename "$patch") (already applied or failed)" >&2
    case "$(basename "$patch")" in
      php-cfg-match.patch)
        if python3 "$ROOT/script/patch-php-cfg-match.py"; then
          echo "Applied $(basename "$patch") (python fallback)"
        else
          echo "ERROR: php-cfg-match.patch failed (match lowering required for self-host spine)." >&2
          return 1
        fi
        ;;
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
  apply_patch "$PATCH_DIR/php-cfg-phi-resolver-null.patch"
  apply_patch "$PATCH_DIR/php-cfg-magic-constants.patch"
  apply_patch "$PATCH_DIR/php-cfg-magic-script-const.patch"
  apply_patch "$PATCH_DIR/php-cfg-magic-line.patch"
  apply_patch "$PATCH_DIR/php-cfg-switch-cond-property.patch"
  apply_patch "$PATCH_DIR/php-cfg-no-arrow-function.patch"
  apply_patch "$PATCH_DIR/php-cfg-no-closure-preg-replace-callback.patch"
  apply_patch "$PATCH_DIR/php-cfg-property-type.patch"
  apply_patch "$PATCH_DIR/php-cfg-assertion-expr-property.patch"
  apply_patch "$PATCH_DIR/php-cfg-match.patch"
  apply_patch "$PATCH_DIR/php-cfg-assignop-coalesce.patch"
  apply_patch "$PATCH_DIR/php-cfg-first-class-callable.patch"
  apply_patch "$PATCH_DIR/php-cfg-arrow-function.patch"
  apply_patch "$PATCH_DIR/php-cfg-anonymous-class.patch"
  apply_patch "$PATCH_DIR/php-cfg-enum.patch"
  apply_patch "$PATCH_DIR/php-cfg-named-args.patch"
  apply_patch "$PATCH_DIR/php-cfg-spread.patch"
  apply_patch "$PATCH_DIR/php-cfg-never-type.patch"
  apply_patch "$PATCH_DIR/php-cfg-intersection-type.patch"
  apply_patch "$PATCH_DIR/php-cfg-union-type.patch"
  apply_patch "$PATCH_DIR/php-cfg-ctor-promotion.patch"
  apply_patch "$PATCH_DIR/php-cfg-attribute-groups.patch"
  apply_patch "$PATCH_DIR/php-cfg-trait-use.patch"
fi

if [[ -d "$ROOT/vendor/ircmaxell/php-types" ]]; then
  apply_patch "$PATCH_DIR/php-types-binaryop-pow.patch"
  apply_patch "$PATCH_DIR/php-types-binaryop-coalesce.patch"
  apply_patch "$PATCH_DIR/php-types-binaryop-spaceship.patch"
  apply_patch "$PATCH_DIR/php-types-str-bool-fns.patch"
  apply_patch "$PATCH_DIR/php-types-str-split-string-array.patch"
  apply_patch "$PATCH_DIR/php-types-readfile-int-false.patch"
  apply_patch "$PATCH_DIR/php-types-stream-context-array-return.patch"
  apply_patch "$PATCH_DIR/php-types-strpbrk-string-false.patch"
  apply_patch "$PATCH_DIR/php-types-crc32-int.patch"
  apply_patch "$PATCH_DIR/php-types-dollars-brace.patch"
  apply_patch "$PATCH_DIR/php-types-missing-parent-no-echo.patch"
  apply_patch "$PATCH_DIR/php-types-mixed-reserved.patch"
  apply_patch "$PATCH_DIR/php-types-nullsafe.patch"
  apply_patch "$PATCH_DIR/php-types-static-var.patch"
  apply_patch "$PATCH_DIR/php-types-nullable-return.patch"
  apply_patch "$PATCH_DIR/php-types-cfg-reference.patch"
  apply_patch "$PATCH_DIR/php-types-nullable-optype-return.patch"
  apply_patch "$PATCH_DIR/php-types-fromvalue-null.patch"
  apply_patch "$PATCH_DIR/php-types-doc-comment-string.patch"
  apply_patch "$PATCH_DIR/php-types-docblock-first-token.patch"
  apply_patch "$PATCH_DIR/php-types-array-shape.patch"
  apply_patch "$PATCH_DIR/php-types-generics-fallback.patch"
  apply_patch "$PATCH_DIR/php-types-generics-list-array.patch"
  apply_patch "$PATCH_DIR/php-types-docblock-trailing-text.patch"
  apply_patch "$PATCH_DIR/php-types-ns-func-call.patch"
  apply_patch "$PATCH_DIR/php-types-arrow-function.patch"
  apply_patch "$PATCH_DIR/php-types-magic-script-const.patch"
  apply_patch "$PATCH_DIR/php-types-first-class-callable.patch"
  apply_patch "$PATCH_DIR/php-types-never-type.patch"
  apply_patch "$PATCH_DIR/php-types-intersection-type.patch"
  apply_patch "$PATCH_DIR/php-types-union-type.patch"
fi

if [[ -d "$ROOT/vendor/pre/plugin" ]]; then
  apply_patch "$PATCH_DIR/pre-plugin-parser-macros.patch"
  apply_patch "$PATCH_DIR/pre-plugin-autoload-prepend.patch"
fi
