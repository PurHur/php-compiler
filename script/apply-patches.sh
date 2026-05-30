#!/usr/bin/env bash
# Apply local patches to vendored dependencies (php-llvm PHP 8.2 / LLVM path fixes).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PATCH_DIR="$ROOT/patches"
VENDOR_LLVM="$ROOT/vendor/ircmaxell/php-llvm"
APPLY_PATCH_FAILURES=()

if [[ ! -d "$VENDOR_LLVM" ]]; then
  echo "vendor/ircmaxell/php-llvm not found; run composer install first" >&2
  exit 1
fi

# Class parseStmt_Class also uses parseExprList($node->implements); scope checks to parseStmt_Enum (#3083, #3419).
php_cfg_enum_implements_parser_applied() {
  local parser="${1:-$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php}"
  [[ -f "$parser" ]] || return 1
  grep -A30 'function parseStmt_Enum' "$parser" 2>/dev/null \
    | grep -q 'parseExprList($node->implements)'
}

patch_already_applied() {
  local patch="$1"
  case "$(basename "$patch")" in
    php-llvm-chooser.patch)
      grep -q 'PHP_COMPILER_LLVM_PATH' "$ROOT/vendor/ircmaxell/php-llvm/lib/Chooser.php" 2>/dev/null
      ;;
    php-types-binaryop-coalesce.patch)
      grep -q "case 'Expr_BinaryOp_Coalesce':" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php" 2>/dev/null
      ;;
    php-types-cast-object.patch)
      grep -q 'exprType instanceof Type' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php" 2>/dev/null
      ;;
    php-types-cast-unset.patch)
      grep -q 'resolveOp_Expr_Cast_Unset' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php" 2>/dev/null
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
      # Upstream php-types has had a few variants of this regex; we only care that
      # @var/@return capture stops before trailing docblock '*' / prose (not \\S+ greed).
      if grep -qF "(@var\\s+(.+?)(?:\\s*\\*\\/|\\s*$))m" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null \
        && grep -qF "(@return\\s+(.+?)(?:\\s*\\*\\/|\\s*$))m" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null; then
        return 0
      fi
      grep -qF "(@var\\s+([^\\s*][^\\s]*))" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null \
        && grep -qF "(@return\\s+([^\\s*][^\\s]*))" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null
      ;;
    php-types-array-shape.patch)
      grep -qF "preg_match('/^array\\\\{" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null
      ;;
    php-types-generics-fallback.patch)
      grep -q "non-empty-string" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null
      ;;
    php-types-generics-list-array.patch)
      grep -qF "preg_match('/^(list|array)" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null
      ;;
    php-types-docblock-trailing-text.patch)
      grep -q "stripTrailingDocText" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null
      ;;
    php-types-fromdecl-junk-fragments.patch)
      grep -q "str_starts_with(\$trimmedDecl, '\\*/')" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null
      ;;
    php-types-ns-func-call.patch)
      grep -q 'function resolveOp_Expr_NsFuncCall' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php" 2>/dev/null
      ;;
    php-types-arrow-function.patch)
      grep -q 'function resolveOp_Expr_ArrowFunction' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php" 2>/dev/null
      ;;
    php-types-closure-unbound-this.patch)
      grep -q "is_string(\$op->extra->value) && '' !== \$op->extra->value" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php" 2>/dev/null
      ;;
    php-types-yield-from.patch)
      grep -q "case 'Expr_YieldFrom':" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php" 2>/dev/null
      ;;
    php-types-incdec-type.patch)
      grep -q "case 'Expr_PostInc':" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php" 2>/dev/null
      ;;
    php-types-str-bool-fns.patch)
      grep -q "'str_contains' => \['bool'" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
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
    php-types-error-get-last-null.patch)
      grep -q "'error_get_last' => \['array|null'" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-types-crc32-int.patch)
      grep -q "'crc32' => \['int'" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-llvm-builder-xor.patch)
      grep -q 'function xor(' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/Builder.php" 2>/dev/null
      ;;
    php-llvm-no-closures-array-map.patch)
      grep -q '\$paramTypes = \[\];' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/Context.php" 2>/dev/null \
        && grep -q '\$elementTypes = \[\];' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/Type/Struct.php" 2>/dev/null
      ;;
    php-llvm-pass-registry-interface.patch)
      grep -q "class PassRegistry implements CorePassRegistry" "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/PassRegistry.php" 2>/dev/null
      ;;
    php-llvm-pass-manager-builder-semicolon.patch)
      grep -q 'PassManagerBuilder as CorePassManagerBuilder;' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/PassManagerBuilder.php" 2>/dev/null
      ;;
    php-llvm-pass-manager-builder-typed-prop.patch)
      grep -q 'LLVMPassManagerBuilderRef \\$passManagerBuilder' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/PassManagerBuilder.php" 2>/dev/null
      ;;
    php-llvm-pass-manager-builder-populate.patch)
      grep -q 'PopulateFunctionPassManager(\\$this->passManagerBuilder, \\$passManager->passManager' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/PassManagerBuilder.php" 2>/dev/null
      ;;
    php-llvm-context-empty-arrays.patch)
      grep -q '\$paramWrapper = null' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/Context.php" 2>/dev/null \
        && grep -qE 'count\(\$elements\) > 0|\$elementWrapper = null' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/Context.php" 2>/dev/null
      ;;
    php-llvm-makearray-empty.patch)
      grep -q 'count(\$elements) === 0' "$ROOT/vendor/ircmaxell/php-llvm/ffi/llvm9.php" 2>/dev/null
      ;;
    php-llvm-memory-buffer-bitcode.patch)
      grep -q 'use llvm\\string_ptr;' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/MemoryBuffer.php" 2>/dev/null \
        && grep -q '\$this->llvm->lib->getFFI()' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/MemoryBuffer.php" 2>/dev/null
      ;;
    php-cfg-mixed-reserved.patch)
      [[ -f "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Type/Mixed_.php" ]] \
        && [[ ! -f "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Type/Mixed.php" ]]
      ;;
    php-cfg-nullsafe.patch)
      [[ -f "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/NullsafePropertyFetch.php" ]]
      ;;
    php-cfg-nullsafe-parser.patch)
      grep -q 'function parseExpr_NullsafePropertyFetch' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-error-suppress-read.patch)
      grep -q 'ZEND_COMPILE_SILENCE' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-error-suppress-simplifier.patch)
      grep -q 'instanceof ErrorSuppressBlock' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Visitor/Simplifier.php" 2>/dev/null
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
    php-cfg-loop-resolver-nested.patch)
      grep -q 'array_slice(\$stack, -\$num, 1)' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/AstVisitor/LoopResolver.php" 2>/dev/null
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
    php-cfg-incdec-expr.patch)
      grep -q 'new Op\\Expr\\PostInc' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
        && [[ -f "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/PostInc.php" ]]
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
      grep -q 'parseStmt_Enum' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
        && php_cfg_enum_implements_parser_applied
      ;;
    php-cfg-enum-implements.patch)
      grep -q 'public $implements' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/Enum_.php" 2>/dev/null \
        && php_cfg_enum_implements_parser_applied
      ;;
    php-cfg-enum-class-method.patch)
      grep -q 'elseif ($stmt instanceof Stmt\\ClassMethod)' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
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
    php-cfg-instanceof-union.patch)
      grep -q 'parseInstanceofClassUnion' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-union-type.patch)
      [[ -f "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Type/Union_.php" ]]
      ;;
    php-cfg-ctor-promotion.patch)
      grep -q 'promotionFlags' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Param.php" 2>/dev/null
      ;;
    php-cfg-attribute-groups.patch)
      grep -q "attrGroups'\] = \$expr->attrGroups" "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-trait-use.patch)
      [[ -f "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/TraitUse.php" ]]
      ;;
    php-cfg-throw-expr.patch)
      [[ -f "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Throw_.php" ]]
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
    php-types-throw-expr.patch)
      grep -q "case 'Expr_Throw':" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php" 2>/dev/null
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
    pre-plugin-autoload-prepend.patch)
      grep -q 'prepend: true' "$ROOT/vendor/pre/plugin/source/autoload.php" 2>/dev/null \
        && ! grep -q ', false,' "$ROOT/vendor/pre/plugin/source/autoload.php" 2>/dev/null
      ;;
    pre-plugin-parser-macros.patch)
      grep -q 'private array \$macros' "$ROOT/vendor/pre/plugin/source/Parser.php" 2>/dev/null
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

apply_php_cfg_yield_from_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local op="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/YieldFrom.php"
  local overlay="$PATCH_DIR/overlays/php-cfg"
  if grep -q 'function parseExpr_YieldFrom' "$parser" 2>/dev/null && [[ -f "$op" ]]; then
    echo "Skip php-cfg-yield-from.patch (already applied)"
    return 0
  fi
  if [[ ! -f "$overlay/Op/Expr/YieldFrom.php" || ! -f "$overlay/yield-from-parser-method.php" ]]; then
    echo "Skip php-cfg-yield-from.patch (overlay files missing)" >&2
    return 1
  fi
  mkdir -p "$(dirname "$op")"
  cp "$overlay/Op/Expr/YieldFrom.php" "$op"
  python3 - "$parser" "$overlay/yield-from-parser-method.php" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
method_path = Path(sys.argv[2])
text = parser_path.read_text()

if 'function parseExpr_YieldFrom' in text:
    parser_path.write_text(text)
    raise SystemExit(0)

anchor = """    protected function parseExpr_Yield(Expr\\Yield_ $expr)
    {"""
if anchor not in text:
    sys.stderr.write("php-cfg-yield-from: parseExpr_Yield anchor not found in Parser.php\n")
    raise SystemExit(1)

insert = method_path.read_text().rstrip("\n") + "\n\n"
parser_path.write_text(text.replace(anchor, insert + anchor, 1))
PY
  echo "Applied php-cfg-yield-from.patch (overlay)"
}

apply_php_cfg_incdec_expr_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local overlay="$PATCH_DIR/overlays/php-cfg"
  if grep -q 'new Op\\Expr\\PostInc' "$parser" 2>/dev/null; then
    echo "Skip php-cfg-incdec-expr.patch (already applied)"
    return 0
  fi
  for class in PostInc PreInc PostDec PreDec; do
    if [[ ! -f "$overlay/Op/Expr/${class}.php" ]]; then
      echo "Skip php-cfg-incdec-expr.patch (overlay ${class}.php missing)" >&2
      return 1
    fi
    mkdir -p "$(dirname "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/${class}.php")"
    cp "$overlay/Op/Expr/${class}.php" "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/${class}.php"
  done
  python3 - "$parser" "$overlay/incdec-parser-methods.php" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
method_path = Path(sys.argv[2])
text = parser_path.read_text()
new_methods = method_path.read_text()

if 'new Op\\Expr\\PostInc' in text:
    parser_path.write_text(text)
    raise SystemExit(0)

old = """    protected function parseExpr_PostDec(Expr\\PostDec $expr)
    {
        $var = $this->parseExprNode($expr->var);
        $read = $this->readVariable($var);
        $write = $this->writeVariable($var);
        $this->block->children[] = $op = new Op\\Expr\\BinaryOp\\Minus($read, new Operand\\Literal(1), $this->mapAttributes($expr));
        $this->block->children[] = new Op\\Expr\\Assign($write, $op->result, $this->mapAttributes($expr));

        return $read;
    }

    protected function parseExpr_PostInc(Expr\\PostInc $expr)
    {
        $var = $this->parseExprNode($expr->var);
        $read = $this->readVariable($var);
        $write = $this->writeVariable($var);
        $this->block->children[] = $op = new Op\\Expr\\BinaryOp\\Plus($read, new Operand\\Literal(1), $this->mapAttributes($expr));
        $this->block->children[] = new Op\\Expr\\Assign($write, $op->result, $this->mapAttributes($expr));

        return $read;
    }

    protected function parseExpr_PreDec(Expr\\PreDec $expr)
    {
        $var = $this->parseExprNode($expr->var);
        $read = $this->readVariable($var);
        $write = $this->writeVariable($var);
        $this->block->children[] = $op = new Op\\Expr\\BinaryOp\\Minus($read, new Operand\\Literal(1), $this->mapAttributes($expr));
        $this->block->children[] = new Op\\Expr\\Assign($write, $op->result, $this->mapAttributes($expr));

        return $op->result;
    }

    protected function parseExpr_PreInc(Expr\\PreInc $expr)
    {
        $var = $this->parseExprNode($expr->var);
        $read = $this->readVariable($var);
        $write = $this->writeVariable($var);
        $this->block->children[] = $op = new Op\\Expr\\BinaryOp\\Plus($read, new Operand\\Literal(1), $this->mapAttributes($expr));
        $this->block->children[] = new Op\\Expr\\Assign($write, $op->result, $this->mapAttributes($expr));

        return $op->result;
    }"""

if old not in text:
    sys.stderr.write("php-cfg-incdec-expr: Parser.php anchor not found\n")
    raise SystemExit(1)

parser_path.write_text(text.replace(old, new_methods.rstrip('\n'), 1))
PY
  echo "Applied php-cfg-incdec-expr.patch (overlay)"
}

apply_php_cfg_yield_keyed_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local overlay="$PATCH_DIR/overlays/php-cfg"
  if grep -A2 'if ($expr->value)' "$parser" 2>/dev/null | grep -q '\$value = \$this->readVariable'; then
    echo "Skip php-cfg-yield-keyed.patch (already applied)"
    return 0
  fi
  if [[ ! -f "$overlay/yield-parser-method.php" ]]; then
    echo "Skip php-cfg-yield-keyed.patch (overlay files missing)" >&2
    return 1
  fi
  python3 - "$parser" "$overlay/yield-parser-method.php" <<'PY'
import re
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
method_path = Path(sys.argv[2])
text = parser_path.read_text()
replacement = method_path.read_text().rstrip("\n") + "\n"
pattern = r"    protected function parseExpr_Yield\(Expr\\Yield_ \$expr\)\s*\{.*?\n    \}\n\n"
match = re.search(pattern, text, re.S)
if not match:
    sys.stderr.write("php-cfg-yield-keyed: parseExpr_Yield method not found in Parser.php\n")
    raise SystemExit(1)
parser_path.write_text(text[: match.start()] + replacement + text[match.end() :])
PY
  echo "Applied php-cfg-yield-keyed.patch (overlay)"
}

# Repair Enum_ Parser ctor when Stmt\\Enum_ gained $implements but Parser still passes Block (#3083).
apply_php_cfg_enum_implements_parser_fix() {
  local parser="$1"
  python3 - "$parser" <<'PY'
import re
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
text = parser_path.read_text()
enum_block = re.search(
    r'protected function parseStmt_Enum\(Stmt\\Enum_ \$node\)\s*\{.*?\n    \}\n',
    text,
    re.S,
)
if enum_block and 'parseExprList($node->implements)' in enum_block.group(0):
    raise SystemExit(0)
pattern = re.compile(
    r"(        \$this->block->children\[\] = new Op\\Stmt\\Enum_\(\n"
    r"            \$name,\n"
    r"            \$backedType,\n)"
    r"(            \$stmtsBlock,)",
    re.MULTILINE,
)
replacement = r"\1            $this->parseExprList($node->implements),\n\2"
new_text, count = pattern.subn(replacement, text, count=1)
if count != 1:
    sys.stderr.write("php-cfg-enum-implements: Enum_ ctor call not found in Parser.php\n")
    raise SystemExit(1)
parser_path.write_text(new_text)
PY
}

apply_php_cfg_enum_class_method_parser_fix() {
  local parser="$1"
  if grep -q 'elseif ($stmt instanceof Stmt\\ClassMethod)' "$parser" 2>/dev/null \
    && grep -A20 'function parseStmt_Enum' "$parser" | grep -q 'Stmt\\ClassMethod'; then
    return 0
  fi
  if ! grep -q 'function parseStmt_Enum' "$parser" 2>/dev/null; then
    echo "Skip php-cfg-enum-class-method.patch (parseStmt_Enum missing)" >&2
    return 1
  fi
  apply_patch "$PATCH_DIR/php-cfg-enum-class-method.patch"
}

apply_php_cfg_enum_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local op="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/Enum_.php"
  local overlay="$PATCH_DIR/overlays/php-cfg"
  mkdir -p "$(dirname "$op")"
  cp "$overlay/Op/Stmt/Enum_.php" "$op"
  if grep -q 'function parseStmt_Enum' "$parser" 2>/dev/null \
    && php_cfg_enum_implements_parser_applied "$parser" \
    && grep -A25 'function parseStmt_Enum' "$parser" | grep -q 'Stmt\\ClassMethod'; then
    echo "Skip php-cfg-enum.patch (already applied)"
    return 0
  fi
  if ! grep -q 'function parseStmt_Enum' "$parser" 2>/dev/null; then
    python3 - "$parser" "$overlay/enum-parser-methods.php" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
method_path = Path(sys.argv[2])
text = parser_path.read_text()
anchor = "    protected function parseStmt_Echo(Stmt\\Echo_ $node)"
insert = method_path.read_text().rstrip("\n") + "\n\n"
if anchor not in text:
    sys.stderr.write("php-cfg-enum: parseStmt_Echo anchor not found in Parser.php\n")
    raise SystemExit(1)
parser_path.write_text(text.replace(anchor, insert + anchor, 1))
PY
    echo "Applied php-cfg-enum.patch (overlay)"
  else
    echo "Repair php-cfg-enum.patch (partial Parser.php)"
  fi
  apply_php_cfg_enum_implements_parser_fix "$parser"
  apply_php_cfg_enum_class_method_parser_fix "$parser"
}

apply_php_cfg_enum_implements_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local op="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/Enum_.php"
  local overlay="$PATCH_DIR/overlays/php-cfg"
  if ! grep -q 'function parseStmt_Enum' "$parser" 2>/dev/null; then
    echo "Skip php-cfg-enum-implements.patch (parseStmt_Enum missing; apply php-cfg-enum.patch first)" >&2
    return 1
  fi
  cp "$overlay/Op/Stmt/Enum_.php" "$op"
  if php_cfg_enum_implements_parser_applied "$parser" \
    && grep -q 'public $implements' "$op" 2>/dev/null; then
    echo "Skip php-cfg-enum-implements.patch (already applied)"
    return 0
  fi
  apply_php_cfg_enum_implements_parser_fix "$parser"
  echo "Applied php-cfg-enum-implements.patch (overlay)"
}

apply_php_cfg_intersection_type_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local printer="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Printer.php"
  local op="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Type/Intersection.php"
  local overlay="$PATCH_DIR/overlays/php-cfg/Op/Type/Intersection.php"
  if [[ -f "$op" ]] && grep -q 'IntersectionType' "$parser" 2>/dev/null; then
    echo "Skip php-cfg-intersection-type.patch (already applied)"
    return 0
  fi
  mkdir -p "$(dirname "$op")"
  cp "$overlay" "$op"
  python3 - "$parser" "$printer" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
printer_path = Path(sys.argv[2])

parser = parser_path.read_text()
if 'Node\\IntersectionType' not in parser:
    anchor = "        throw new \\LogicException('Unknown type node: '.$node->getType());"
    insert = """        if ($node instanceof Node\\IntersectionType) {
            $types = [];
            foreach ($node->types as $sub) {
                $types[] = $this->parseTypeNode($sub);
            }

            return new Op\\Type\\Intersection($types, $this->mapAttributes($node));
        }

"""
    if anchor not in parser:
        sys.stderr.write("php-cfg-intersection-type: throw anchor not found in Parser.php\\n")
        raise SystemExit(1)
    parser = parser.replace(anchor, insert + anchor, 1)
    parser_path.write_text(parser)

printer = printer_path.read_text()
if 'Op\\\\Type\\\\Intersection' not in printer:
    anchor = "        if ($type instanceof Op\\Type\\Literal) {"
    insert = """        if ($type instanceof Op\\Type\\Intersection) {
            return implode('&', array_map(
                fn (Op\\Type $t) => $this->renderType($t),
                $type->types
            ));
        }
"""
    if anchor not in printer:
        sys.stderr.write("php-cfg-intersection-type: Literal anchor not found in Printer.php\\n")
        raise SystemExit(1)
    printer = printer.replace(anchor, insert + anchor, 1)
    printer_path.write_text(printer)
PY
  echo "Applied php-cfg-intersection-type.patch (overlay)"
}

apply_php_cfg_instanceof_union_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local instanceof_op="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/InstanceOf_.php"
  local methods="$PATCH_DIR/overlays/php-cfg/instanceof-union-parser-methods.php"
  if grep -q 'parseInstanceofClassUnion' "$parser" 2>/dev/null \
    && grep -q 'classUnion' "$instanceof_op" 2>/dev/null; then
    echo "Skip php-cfg-instanceof-union.patch (already applied)"
    return 0
  fi
  python3 - "$parser" "$instanceof_op" "$methods" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
instanceof_path = Path(sys.argv[2])
methods_path = Path(sys.argv[3])
methods = methods_path.read_text()

parser = parser_path.read_text()
if 'parseInstanceofClassUnion' not in parser:
    anchor = "    protected function parseExpr_Instanceof(Expr\\Instanceof_ $expr)"
    if anchor not in parser:
        sys.stderr.write("php-cfg-instanceof-union: parseExpr_Instanceof anchor not found\\n")
        raise SystemExit(1)
    parser = parser.replace(anchor, methods + anchor, 1)
    old_body = """        $var = $this->readVariable($this->parseExprNode($expr->expr));
        $class = $this->readVariable($this->parseExprNode($expr->class));"""
    new_body = """        $var = $this->readVariable($this->parseExprNode($expr->expr));
        $union = $this->parseInstanceofClassUnion($expr->class);
        if (null !== $union) {
            $class = $this->readVariable(new Literal(''));
            $op = new Op\\Expr\\InstanceOf_($var, $class, $this->mapAttributes($expr));
            $op->classUnion = $union;

            return $op;
        }
        $class = $this->readVariable($this->parseExprNode($expr->class));"""
    if old_body not in parser:
        sys.stderr.write("php-cfg-instanceof-union: instanceof body anchor not found\\n")
        raise SystemExit(1)
    parser = parser.replace(old_body, new_body, 1)
    parser_path.write_text(parser)

instanceof_src = instanceof_path.read_text()
if 'classUnion' not in instanceof_src:
    anchor = "use PhpCfg\\Operand;"
    insert = "use PhpCfg\\Operand;\nuse PHPCfg\\Op\\Type;"
    if anchor not in instanceof_src:
        sys.stderr.write("php-cfg-instanceof-union: Operand import anchor not found\\n")
        raise SystemExit(1)
    instanceof_src = instanceof_src.replace(anchor, insert, 1)
    prop_anchor = "    public $class;\n"
    prop_insert = """    public $class;

    /** @var null|Type\\Union_ union RHS for $obj instanceof (A|B) (#3461) */
    public $classUnion = null;
"""
    if prop_anchor not in instanceof_src:
        sys.stderr.write("php-cfg-instanceof-union: class property anchor not found\\n")
        raise SystemExit(1)
    instanceof_src = instanceof_src.replace(prop_anchor, prop_insert, 1)
    instanceof_path.write_text(instanceof_src)
PY
  echo "Applied php-cfg-instanceof-union.patch (overlay)"
}

apply_php_cfg_union_type_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local printer="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Printer.php"
  local op="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Type/Union_.php"
  if [[ -f "$op" ]] && grep -q 'UnionType' "$parser" 2>/dev/null; then
    echo "Skip php-cfg-union-type.patch (already applied)"
    return 0
  fi
  mkdir -p "$(dirname "$op")"
  cat >"$op" <<'PHP'
<?php

declare(strict_types=1);

namespace PHPCfg\Op\Type;

use PHPCfg\Op\Type;

class Union_ extends Type
{
    /** @var Type[] */
    public $types;

    public function __construct(array $types, array $attributes = [])
    {
        $this->types = $types;
    }
}
PHP
  python3 - "$parser" "$printer" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
printer_path = Path(sys.argv[2])

parser = parser_path.read_text()
if 'Node\\UnionType' not in parser:
    anchor = "        throw new \\LogicException('Unknown type node: '.$node->getType());"
    insert = """        if ($node instanceof Node\\UnionType) {
            $types = [];
            foreach ($node->types as $sub) {
                $types[] = $this->parseTypeNode($sub);
            }

            return new Op\\Type\\Union_($types, $this->mapAttributes($node));
        }

"""
    if anchor not in parser:
        sys.stderr.write("php-cfg-union-type: throw anchor not found in Parser.php\\n")
        raise SystemExit(1)
    parser = parser.replace(anchor, insert + anchor, 1)
    parser_path.write_text(parser)

printer = printer_path.read_text()
if 'Op\\\\Type\\\\Union_' not in printer:
    anchor = "        if ($type instanceof Op\\Type\\Literal) {"
    insert = """        if ($type instanceof Op\\Type\\Union_) {
            return implode('|', array_map(
                fn (Op\\Type $t) => $this->renderType($t),
                $type->types
            ));
        }
"""
    if anchor not in printer:
        sys.stderr.write("php-cfg-union-type: Literal anchor not found in Printer.php\\n")
        raise SystemExit(1)
    printer = printer.replace(anchor, insert + anchor, 1)
    printer_path.write_text(printer)
PY
  echo "Applied php-cfg-union-type.patch (overlay)"
}

apply_php_cfg_attribute_groups_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  if grep -q "attrGroups'\] = \$expr->attrGroups" "$parser" 2>/dev/null; then
    echo "Skip php-cfg-attribute-groups.patch (already applied)"
    return 0
  fi
  python3 - "$parser" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
old = """    private function mapAttributes(Node $expr)
    {
        return array_merge(
            [
                'filename' => $this->fileName,
                'doccomment' => $expr->getDocComment(),
            ],
            $expr->getAttributes()
        );
    }"""
new = """    private function mapAttributes(Node $expr)
    {
        $attrs = array_merge(
            [
                'filename' => $this->fileName,
                'doccomment' => $expr->getDocComment(),
            ],
            $expr->getAttributes()
        );
        if (property_exists($expr, 'attrGroups') && [] !== $expr->attrGroups) {
            $attrs['attrGroups'] = $expr->attrGroups;
        }
        return $attrs;
    }"""
if old not in text:
    sys.stderr.write("php-cfg-attribute-groups: mapAttributes anchor not found\n")
    raise SystemExit(1)
path.write_text(text.replace(old, new, 1))
PY
  echo "Applied php-cfg-attribute-groups.patch (overlay)"
}

apply_php_types_intersection_type_overlay() {
  local target="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php"
  if grep -q 'instanceof CfgType\\Intersection' "$target" 2>/dev/null; then
    echo "Skip php-types-intersection-type.patch (already applied)"
    return 0
  fi
  python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()

anchor = "        throw new \\LogicException('Unsupported declaration type: '.get_class($decl));"
if anchor not in text:
    sys.stderr.write("php-types-intersection-type: throw anchor not found\n")
    raise SystemExit(1)

insert = """        if ($decl instanceof CfgType\\Union_) {
            $subs = [];
            foreach ($decl->types as $sub) {
                $subs[] = self::fromTypeDecl($sub);
            }

            return new self(self::TYPE_UNION, $subs);
        }
        if ($decl instanceof CfgType\\Intersection) {
            $subs = [];
            foreach ($decl->types as $sub) {
                $subs[] = self::fromTypeDecl($sub);
            }

            return new self(self::TYPE_INTERSECTION, $subs);
        }

"""
path.write_text(text.replace(anchor, insert + anchor, 1))
PY
  echo "Applied php-types-intersection-type.patch (overlay)"
}

apply_php_types_union_type_reconstructor_overlay() {
  local target="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
  if grep -q 'instanceof Op\\Type\\Union_' "$target" 2>/dev/null; then
    return 0
  fi
  python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
anchor = """        } elseif ($type instanceof Op\\Type\\Never_) {
            return Type::never();
        }

        throw new \\LogicException('Unknown Op\\\\Type provided: '.get_class($type));"""
insert = """        } elseif ($type instanceof Op\\Type\\Never_) {
            return Type::never();
        } elseif ($type instanceof Op\\Type\\Union_) {
            $subs = [];
            foreach ($type->types as $sub) {
                $subs[] = $this->resolveOpType($sub);
            }

            return (new Type(Type::TYPE_UNION, $subs))->simplify();
        }

        throw new \\LogicException('Unknown Op\\\\Type provided: '.get_class($type));"""
if anchor not in text:
    sys.stderr.write("php-types-union-type: TypeReconstructor Never_ anchor not found\n")
    raise SystemExit(1)
path.write_text(text.replace(anchor, insert, 1))
PY
  echo "Applied php-types-union-type.patch (TypeReconstructor overlay)"
}

apply_php_types_union_type_overlay() {
  local type_php="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php"
  local recon="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
  local need_type=1
  local need_recon=1

  if grep -q 'instanceof CfgType\\Union_' "$type_php" 2>/dev/null; then
    need_type=0
  fi
  if grep -q 'instanceof Op\\Type\\Union_' "$recon" 2>/dev/null; then
    need_recon=0
  fi

  if [[ "$need_type" -eq 0 && "$need_recon" -eq 0 ]]; then
    echo "Skip php-types-union-type.patch (already applied)"
    return 0
  fi

  if [[ "$need_type" -eq 1 ]]; then
    # Union fromTypeDecl is inserted by php-types-intersection-type overlay.
    apply_php_types_intersection_type_overlay
  fi

  if [[ "$need_recon" -eq 1 ]]; then
    apply_php_types_union_type_reconstructor_overlay
  fi
}

apply_php_types_closure_unbound_this_overlay() {
  local target="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
  if grep -q "is_string(\$op->extra->value) && '' !== \$op->extra->value" "$target" 2>/dev/null; then
    echo "Skip php-types-closure-unbound-this.patch (already applied)"
    return 0
  fi
  python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
old1 = """            } elseif ($op instanceof Operand\\BoundVariable && $op->scope === Operand\\BoundVariable::SCOPE_OBJECT) {
                $resolved[$op] = $op->type = Type::fromDecl($op->extra->value);
            } elseif ($op instanceof Operand\\Literal) {"""
new1 = """            } elseif ($op instanceof Operand\\BoundVariable && $op->scope === Operand\\BoundVariable::SCOPE_OBJECT) {
                if ($op->extra instanceof Operand\\Literal && is_string($op->extra->value) && '' !== $op->extra->value) {
                    $resolved[$op] = $op->type = Type::fromDecl($op->extra->value);
                } else {
                    $resolved[$op] = $op->type = Type::unknown();
                }
            } elseif ($op instanceof Operand\\Literal) {"""
old2 = """        if ($var instanceof Operand\\BoundVariable && $var->scope === Operand\\BoundVariable::SCOPE_OBJECT) {
            assert($var->extra instanceof Operand\\Literal);

            return Type::fromDecl($var->extra->value);
        }"""
new2 = """        if ($var instanceof Operand\\BoundVariable && $var->scope === Operand\\BoundVariable::SCOPE_OBJECT) {
            if ($var->extra instanceof Operand\\Literal && is_string($var->extra->value) && '' !== $var->extra->value) {
                return Type::fromDecl($var->extra->value);
            }

            return Type::unknown();
        }"""
if old1 in text and old2 in text:
    path.write_text(text.replace(old1, new1, 1).replace(old2, new2, 1))
    raise SystemExit(0)
sys.stderr.write("php-types-closure-unbound-this: TypeReconstructor anchor not found\n")
raise SystemExit(1)
PY
  echo "Applied php-types-closure-unbound-this.patch (overlay)"
}

apply_php_types_first_class_callable_overlay() {
  local target="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
  if grep -q 'FirstClassCallable::KIND_METHOD' "$target" 2>/dev/null; then
    echo "Skip php-types-first-class-callable.patch (already applied)"
    return 0
  fi
  python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
fcc_case = """            case 'Expr_FirstClassCallable':
                if (\\PHPCfg\\Op\\Expr\\FirstClassCallable::KIND_METHOD === $op->kind) {
                    return [new Type(Type::TYPE_ARRAY)];
                }

                return [Type::string()];
"""
anchors = [
    (
        """            case 'Expr_Yield':
            case 'Expr_YieldFrom':
            case 'Expr_Include':
                // TODO: we may be able to determine these...
                return false;
            case 'Expr_MagicScriptConst':""",
        """            case 'Expr_Yield':
            case 'Expr_YieldFrom':
            case 'Expr_Include':
                // TODO: we may be able to determine these...
                return false;
""" + fcc_case + """            case 'Expr_MagicScriptConst':""",
    ),
    (
        """            case 'Expr_Yield':
            case 'Expr_YieldFrom':
            case 'Expr_Include':
                // TODO: we may be able to determine these...
                return false;
        }

        throw new \\LogicException('Unknown variable op found: '.$op->getType());""",
        """            case 'Expr_Yield':
            case 'Expr_YieldFrom':
            case 'Expr_Include':
                // TODO: we may be able to determine these...
                return false;
""" + fcc_case + """        }

        throw new \\LogicException('Unknown variable op found: '.$op->getType());""",
    ),
]
for old, new in anchors:
    if old in text:
        path.write_text(text.replace(old, new, 1))
        raise SystemExit(0)
sys.stderr.write("php-types-first-class-callable: TypeReconstructor anchor not found\n")
raise SystemExit(1)
PY
  echo "Applied php-types-first-class-callable.patch (overlay)"
}

apply_php_types_magic_script_const_overlay() {
  local target="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
  if grep -q 'MagicScriptConst::KIND_LINE' "$target" 2>/dev/null; then
    echo "Skip php-types-magic-script-const.patch (already applied)"
    return 0
  fi
  python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
msc_case = """            case 'Expr_MagicScriptConst':
                if (\\PHPCfg\\Op\\Expr\\MagicScriptConst::KIND_LINE === $op->kind) {
                    return [Type::int()];
                }

                return [Type::string()];
"""
anchors = [
    (
        """            case 'Expr_Yield':
            case 'Expr_YieldFrom':
            case 'Expr_Include':
                // TODO: we may be able to determine these...
                return false;
        }

        throw new \\LogicException('Unknown variable op found: '.$op->getType());""",
        """            case 'Expr_Yield':
            case 'Expr_YieldFrom':
            case 'Expr_Include':
                // TODO: we may be able to determine these...
                return false;
""" + msc_case + """        }

        throw new \\LogicException('Unknown variable op found: '.$op->getType());""",
    ),
    (
        """            case 'Expr_FirstClassCallable':
                if (\\PHPCfg\\Op\\Expr\\FirstClassCallable::KIND_METHOD === $op->kind) {
                    return [new Type(Type::TYPE_ARRAY)];
                }

                return [Type::string()];
        }

        throw new \\LogicException('Unknown variable op found: '.$op->getType());""",
        """            case 'Expr_FirstClassCallable':
                if (\\PHPCfg\\Op\\Expr\\FirstClassCallable::KIND_METHOD === $op->kind) {
                    return [new Type(Type::TYPE_ARRAY)];
                }

                return [Type::string()];
""" + msc_case + """        }

        throw new \\LogicException('Unknown variable op found: '.$op->getType());""",
    ),
]
for old, new in anchors:
    if old in text:
        path.write_text(text.replace(old, new, 1))
        raise SystemExit(0)
sys.stderr.write("php-types-magic-script-const: TypeReconstructor anchor not found\n")
raise SystemExit(1)
PY
  echo "Applied php-types-magic-script-const.patch (overlay)"
}

apply_php_types_str_bool_fns_overlay() {
  local target="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php"
  if grep -q "'str_contains' => \['bool'" "$target" 2>/dev/null; then
    echo "Skip php-types-str-bool-fns.patch (already applied)"
    return 0
  fi
  python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
needle = "        'strspn' => ['int', 'str' => 'string', 'mask' => 'string', 'start=' => 'int', 'len=' => 'int'],\n"
insert = needle + (
    "        'str_contains' => ['bool', 'haystack' => 'string', 'needle' => 'string'],\n"
    "        'str_ends_with' => ['bool', 'haystack' => 'string', 'needle' => 'string'],\n"
    "        'str_starts_with' => ['bool', 'haystack' => 'string', 'needle' => 'string'],\n"
)
if needle not in text:
    sys.stderr.write("php-types-str-bool-fns: strspn anchor not found\n")
    raise SystemExit(1)
path.write_text(text.replace(needle, insert, 1))
PY
  echo "Applied php-types-str-bool-fns.patch (overlay)"
}

apply_php_types_docblock_trailing_text_overlay() {
  local target="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php"
  if patch_already_applied "$PATCH_DIR/php-types-docblock-trailing-text.patch"; then
    echo "Skip php-types-docblock-trailing-text.patch (already applied)"
    return 0
  fi
  python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()

if 'stripTrailingDocText' not in text:
    anchor = "        }\n        switch (strtolower($decl)) {"
    if anchor not in text:
        sys.stderr.write("php-types-docblock-trailing-text: fromDecl switch anchor not found\n")
        raise SystemExit(1)
    insert = "        }\n        $decl = self::stripTrailingDocText($decl);\n        switch (strtolower($decl)) {"
    text = text.replace(anchor, insert, 1)

    class_end = "\n}\n"
    if not text.endswith(class_end):
        sys.stderr.write("php-types-docblock-trailing-text: expected Type.php to end with class brace\n")
        raise SystemExit(1)
    helper = """
    private static function stripTrailingDocText(string $decl): string
    {
        $decl = trim($decl);
        if ('' === $decl) {
            return $decl;
        }
        if (false === strpos($decl, ' ')) {
            return $decl;
        }

        $depthAngle = 0;
        $depthParen = 0;
        $depthSquare = 0;
        $depthCurly = 0;

        $len = strlen($decl);
        for ($i = 0; $i < $len; $i++) {
            $ch = $decl[$i];
            switch ($ch) {
                case '<':
                    $depthAngle++;
                    break;
                case '>':
                    if ($depthAngle > 0) {
                        $depthAngle--;
                    }
                    break;
                case '(':
                    $depthParen++;
                    break;
                case ')':
                    if ($depthParen > 0) {
                        $depthParen--;
                    }
                    break;
                case '[':
                    $depthSquare++;
                    break;
                case ']':
                    if ($depthSquare > 0) {
                        $depthSquare--;
                    }
                    break;
                case '{':
                    $depthCurly++;
                    break;
                case '}':
                    if ($depthCurly > 0) {
                        $depthCurly--;
                    }
                    break;
                default:
                    if ($ch <= ' ' && 0 === $depthAngle && 0 === $depthParen && 0 === $depthSquare && 0 === $depthCurly) {
                        return trim(substr($decl, 0, $i));
                    }
                    break;
            }
        }

        return $decl;
    }
"""
    text = text[: -len(class_end)] + helper + class_end

path.write_text(text)
PY
  echo "Applied php-types-docblock-trailing-text.patch (overlay)"
}

apply_php_types_fromdecl_junk_fragments_overlay() {
  local target="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php"
  if patch_already_applied "$PATCH_DIR/php-types-fromdecl-junk-fragments.patch"; then
    echo "Skip php-types-fromdecl-junk-fragments.patch (already applied)"
    return 0
  fi
  python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()

needle = "        $decl = self::stripTrailingDocText($decl);\n"
if needle not in text:
    sys.stderr.write("php-types-fromdecl-junk-fragments: stripTrailingDocText line not found\n")
    raise SystemExit(1)

insert = needle + (
    "        $trimmedDecl = trim($decl);\n"
    "        if ('' === $trimmedDecl || '*' === $trimmedDecl || '*/' === $trimmedDecl\n"
    "            || str_starts_with($trimmedDecl, '*/')) {\n"
    "            return self::mixed();\n"
    "        }\n"
)

if "$trimmedDecl" not in text:
    text = text.replace(needle, insert, 1)
    path.write_text(text)
PY
  echo "Applied php-types-fromdecl-junk-fragments.patch (overlay)"
}

apply_php_cfg_magic_script_const_overlay() {
  local op="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/MagicScriptConst.php"
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local overlay_op="$PATCH_DIR/overlays/php-cfg/Op/Expr/MagicScriptConst.php"
  if grep -q 'MagicScriptConst::KIND_DIR' "$parser" 2>/dev/null; then
    echo "Skip php-cfg-magic-script-const.patch (already applied)"
    return 0
  fi
  if [[ ! -f "$overlay_op" ]]; then
    echo "Skip php-cfg-magic-script-const.patch (overlay missing)" >&2
    return 1
  fi
  mkdir -p "$(dirname "$op")"
  cp "$overlay_op" "$op"
  python3 - "$parser" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
text = parser_path.read_text()
replacements = [
    (
        "            case 'Scalar_MagicConst_Dir':\n"
        "                return new Literal(dirname($this->fileName));",
        "            case 'Scalar_MagicConst_Dir':\n"
        "                $op = new Op\\Expr\\MagicScriptConst(Op\\Expr\\MagicScriptConst::KIND_DIR, $this->mapAttributes($scalar));\n"
        "                $this->block->children[] = $op;\n"
        "                return $op->result;",
    ),
    (
        "            case 'Scalar_MagicConst_File':\n"
        "                return new Literal($this->fileName);",
        "            case 'Scalar_MagicConst_File':\n"
        "                $op = new Op\\Expr\\MagicScriptConst(Op\\Expr\\MagicScriptConst::KIND_FILE, $this->mapAttributes($scalar));\n"
        "                $this->block->children[] = $op;\n"
        "                return $op->result;",
    ),
    (
        "            case 'Scalar_MagicConst_File':\n"
        "                $op = new Op\\Expr\\MagicScriptConst(Op\\Expr\\MagicScriptConst::KIND_FILE, $this->mapAttributes($scalar));\n"
        "                $this->block->children[] = $op;\n"
        "                return $op->result;\n"
        "            case 'Scalar_MagicConst_Namespace':",
        "            case 'Scalar_MagicConst_File':\n"
        "                $op = new Op\\Expr\\MagicScriptConst(Op\\Expr\\MagicScriptConst::KIND_FILE, $this->mapAttributes($scalar));\n"
        "                $this->block->children[] = $op;\n"
        "                return $op->result;\n"
        "            case 'Scalar_MagicConst_Line':\n"
        "                $op = new Op\\Expr\\MagicScriptConst(Op\\Expr\\MagicScriptConst::KIND_LINE, $this->mapAttributes($scalar));\n"
        "                $this->block->children[] = $op;\n"
        "                return $op->result;\n"
        "            case 'Scalar_MagicConst_Namespace':",
    ),
]
for old, new in replacements:
    if old in text:
        text = text.replace(old, new, 1)
        parser_path.write_text(text)
        print("Applied php-cfg-magic-script-const.patch (overlay)")
        raise SystemExit(0)
sys.stderr.write("php-cfg-magic-script-const: Parser.php anchor not found\n")
raise SystemExit(1)
PY
}

apply_php_cfg_magic_constants_overlay() {
  local target="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/AstVisitor/MagicStringResolver.php"
  local overlay="$PATCH_DIR/overlays/php-cfg/MagicStringResolver.php"
  if [[ ! -f "$overlay" ]]; then
    echo "Skip php-cfg-magic-constants.patch (overlay missing)" >&2
    return 1
  fi
  if patch_already_applied "$PATCH_DIR/php-cfg-magic-constants.patch" \
    && grep -q 'traitStack' "$target" 2>/dev/null \
    && grep -q 'functionStack' "$target" 2>/dev/null \
    && grep -q 'MagicConst\\Method' "$target" 2>/dev/null \
    && grep -A3 'MagicConst\\Method' "$target" | grep -q 'functionStack'; then
    echo "Skip php-cfg-magic-constants.patch (already applied)"
    return 0
  fi
  cp "$overlay" "$target"
  echo "Applied php-cfg-magic-constants.patch (overlay)"
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

apply_php_llvm_no_closures_array_map_overlay() {
  local context="$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/Context.php"
  local struct="$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/Type/Struct.php"
  if patch_already_applied "$PATCH_DIR/php-llvm-no-closures-array-map.patch"; then
    echo "Skip php-llvm-no-closures-array-map.patch (already applied)"
    return 0
  fi
  python3 - "$context" "$struct" <<'PY'
import sys
from pathlib import Path

context_path = Path(sys.argv[1])
struct_path = Path(sys.argv[2])

context = context_path.read_text()
old_function_type = """    public function functionType(CoreType $returnType, bool $isVarArgs, CoreType ... $parameters): CoreFunctionType {
        $paramWrapper = $this->llvm->lib->makeArray(
            LLVMTypeRef_ptr::class,
            array_map(
                function(Type $type) {
                    return $type->type;
                }, 
                $parameters
            )
        );
        return $this->llvm->factory->type(
            $this, 
            $this->llvm->lib->LLVMFunctionType(
                $returnType->type,
                $paramWrapper,
                count($parameters),
                // LLVM is stupid, and even though the type is declared LLVMBool, it's not, and is a normal "1/0" bool instead of the weird reversed...
                $isVarArgs ? 1 : 0
            )
        );
    }"""
new_function_type = """    public function functionType(CoreType $returnType, bool $isVarArgs, CoreType ... $parameters): CoreFunctionType {
        $paramWrapper = null;
        if (count($parameters) > 0) {
            $paramTypes = [];
            foreach ($parameters as $type) {
                $paramTypes[] = $type->type;
            }
            $paramWrapper = $this->llvm->lib->makeArray(
                LLVMTypeRef_ptr::class,
                $paramTypes
            );
        }
        return $this->llvm->factory->type(
            $this, 
            $this->llvm->lib->LLVMFunctionType(
                $returnType->type,
                $paramWrapper,
                count($parameters),
                // LLVM is stupid, and even though the type is declared LLVMBool, it's not, and is a normal "1/0" bool instead of the weird reversed...
                $isVarArgs ? 1 : 0
            )
        );
    }"""

old_struct_type = """    public function structType(bool $packed, CoreType ... $elements): CoreType {
        $elementWrapper = $this->llvm->lib->makeArray(
            LLVMTypeRef_ptr::class,
            array_map(
                function(Type $type) {
                    return $type->type;
                }, 
                $elements
            )
        );
        return $this->llvm->factory->type(
            $this,
            $this->llvm->lib->LLVMStructTypeInContext(
                $this->context,
                $elementWrapper,
                count($elements),
                $this->llvm->toBool($packed)
            )
        );
    }"""
new_struct_type = """    public function structType(bool $packed, CoreType ... $elements): CoreType {
        $elementWrapper = null;
        if (count($elements) > 0) {
            $elementTypes = [];
            foreach ($elements as $type) {
                $elementTypes[] = $type->type;
            }
            $elementWrapper = $this->llvm->lib->makeArray(
                LLVMTypeRef_ptr::class,
                $elementTypes
            );
        }
        return $this->llvm->factory->type(
            $this,
            $this->llvm->lib->LLVMStructTypeInContext(
                $this->context,
                $elementWrapper,
                count($elements),
                $this->llvm->toBool($packed)
            )
        );
    }"""

if old_function_type not in context or old_struct_type not in context:
    sys.stderr.write("php-llvm-no-closures-array-map: expected Context.php anchors not found\n")
    sys.exit(1)
context = context.replace(old_function_type, new_function_type, 1)
context = context.replace(old_struct_type, new_struct_type, 1)
context_path.write_text(context)

struct = struct_path.read_text()
old_set_body = """    public function setBody(bool $packed, CoreType ... $elements): void {
        $elementWrapper = $this->llvm->lib->makeArray(
            LLVMTypeRef_ptr::class,
            array_map(
                function(Type $type) {
                    return $type->type;
                }, 
                $elements
            )
        );
        $this->llvm->lib->LLVMStructSetBody(
            $this->type,
            $elementWrapper,
            count($elements),
            $this->llvm->toBool($packed)
        );
    }"""
new_set_body = """    public function setBody(bool $packed, CoreType ... $elements): void {
        $elementTypes = [];
        foreach ($elements as $type) {
            $elementTypes[] = $type->type;
        }
        $elementWrapper = $this->llvm->lib->makeArray(
            LLVMTypeRef_ptr::class,
            $elementTypes
        );
        $this->llvm->lib->LLVMStructSetBody(
            $this->type,
            $elementWrapper,
            count($elements),
            $this->llvm->toBool($packed)
        );
    }"""
if old_set_body not in struct:
    sys.stderr.write("php-llvm-no-closures-array-map: expected Struct.php anchor not found\n")
    sys.exit(1)
struct_path.write_text(struct.replace(old_set_body, new_set_body, 1))
PY
  echo "Applied php-llvm-no-closures-array-map.patch (overlay)"
}

record_patch_failure() {
  local patch_name="$1"
  local detail="${2:-}"
  APPLY_PATCH_FAILURES+=("$patch_name")
  echo "ERROR: failed to apply ${patch_name}" >&2
  if [[ -n "$detail" ]]; then
    echo "  ${detail}" >&2
  fi
  echo "  Hint: git -C \"${ROOT}\" apply --check -p0 \"${PATCH_DIR}/${patch_name}\"" >&2
}

# When git apply / patch(1) cannot run (stale hunk lines, corrupt diff), detect prior apply
# from the first added line or new-file target in the patch file.
patch_marker_present() {
  local patch="$1"
  local old_path new_path marker
  old_path="$(grep -m1 '^--- ' "$patch" | awk '{print $2}')"
  new_path="$(grep -m1 '^\+\+\+ ' "$patch" | awk '{print $2}')"
  old_path="${old_path#a/}"
  new_path="${new_path#b/}"
  if [[ "$old_path" == "/dev/null" ]]; then
    [[ -n "$new_path" && -f "$ROOT/$new_path" ]]
    return
  fi
  marker="$(grep -m1 '^+[^+]' "$patch" | sed 's/^+//' || true)"
  if [[ -z "$marker" || -z "$old_path" ]]; then
    return 1
  fi
  grep -qF "$marker" "$ROOT/$old_path" 2>/dev/null
}

apply_patch() {
  local patch="$1"
  local patch_name
  patch_name="$(basename "$patch")"
  if [[ ! -f "$patch" ]]; then
    return 0
  fi
  if [[ "$(basename "$patch")" == "php-cfg-yield-from.patch" ]]; then
    apply_php_cfg_yield_from_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-incdec-expr.patch" ]]; then
    apply_php_cfg_incdec_expr_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-yield-keyed.patch" ]]; then
    apply_php_cfg_yield_keyed_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-magic-constants.patch" ]]; then
    apply_php_cfg_magic_constants_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-magic-script-const.patch" ]]; then
    apply_php_cfg_magic_script_const_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-enum.patch" ]]; then
    apply_php_cfg_enum_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-enum-implements.patch" ]]; then
    apply_php_cfg_enum_implements_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-intersection-type.patch" ]]; then
    apply_php_cfg_intersection_type_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-instanceof-union.patch" ]]; then
    apply_php_cfg_instanceof_union_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-attribute-groups.patch" ]]; then
    apply_php_cfg_attribute_groups_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-str-bool-fns.patch" ]]; then
    apply_php_types_str_bool_fns_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-docblock-trailing-text.patch" ]]; then
    apply_php_types_docblock_trailing_text_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-fromdecl-junk-fragments.patch" ]]; then
    apply_php_types_fromdecl_junk_fragments_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-magic-script-const.patch" ]]; then
    apply_php_types_magic_script_const_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-closure-unbound-this.patch" ]]; then
    apply_php_types_closure_unbound_this_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-first-class-callable.patch" ]]; then
    apply_php_types_first_class_callable_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-intersection-type.patch" ]]; then
    apply_php_types_intersection_type_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-union-type.patch" ]]; then
    apply_php_types_union_type_overlay
    return $?
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
  if [[ "$(basename "$patch")" == "php-llvm-no-closures-array-map.patch" ]]; then
    apply_php_llvm_no_closures_array_map_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-union-type.patch" ]]; then
    apply_php_cfg_union_type_overlay
    return $?
  fi
  if patch_already_applied "$patch"; then
    echo "Skip ${patch_name} (already applied)"
    return 0
  fi
  if git -C "$ROOT" apply --check -p0 "$patch" >/dev/null 2>&1; then
    git -C "$ROOT" apply -p0 "$patch"
    echo "Applied ${patch_name}"
    return 0
  fi
  if command -v patch >/dev/null 2>&1; then
    if patch -p0 --dry-run -s -f < "$patch" >/dev/null 2>&1; then
      patch -p0 -s -f < "$patch" >/dev/null 2>&1
      echo "Applied ${patch_name} (patch(1))"
      return 0
    fi
    if patch -p0 --reverse --dry-run -s -f < "$patch" >/dev/null 2>&1; then
      echo "Skip ${patch_name} (already applied)"
      return 0
    fi
  fi
  case "${patch_name}" in
    php-cfg-match.patch)
      if python3 "$ROOT/script/patch-php-cfg-match.py"; then
        echo "Applied ${patch_name} (python fallback)"
        return 0
      fi
      record_patch_failure "${patch_name}" "match lowering required for self-host spine"
      return 1
      ;;
    php-cfg-strict-types.patch)
      record_patch_failure "${patch_name}" "required for AOT (declare(strict_types))"
      return 1
      ;;
    *)
      if patch_marker_present "$patch"; then
        echo "Skip ${patch_name} (already applied)"
        return 0
      fi
      record_patch_failure "${patch_name}"
      return 1
      ;;
  esac
}

apply_patch "$PATCH_DIR/php-llvm-chooser.patch"
apply_patch "$PATCH_DIR/php-llvm-no-closures-array-map.patch"
apply_patch "$PATCH_DIR/php-llvm-context-empty-arrays.patch"
apply_patch "$PATCH_DIR/php-llvm-makearray-empty.patch"
apply_patch "$PATCH_DIR/php-llvm-builder-select.patch"
apply_patch "$PATCH_DIR/php-llvm-value-addincoming.patch"
apply_patch "$PATCH_DIR/php-llvm-llvmabstract-value-addincoming.patch"
apply_patch "$PATCH_DIR/php-llvm-builder-and-or.patch"
apply_patch "$PATCH_DIR/php-llvm-builder-xor.patch"
apply_patch "$PATCH_DIR/php-llvm-pass-registry-interface.patch"
apply_patch "$PATCH_DIR/php-llvm-pass-manager-builder-semicolon.patch"
apply_patch "$PATCH_DIR/php-llvm-pass-manager-builder-typed-prop.patch"
apply_patch "$PATCH_DIR/php-llvm-pass-manager-builder-populate.patch"
apply_patch "$PATCH_DIR/php-llvm-memory-buffer-bitcode.patch"
apply_patch "$PATCH_DIR/php-llvm-x86-posix-fallback.patch"

# php-cfg before php-types: php-types-mixed-reserved.patch references Op\Type\Mixed_.
if [[ -d "$ROOT/vendor/ircmaxell/php-cfg" ]]; then
  apply_patch "$PATCH_DIR/php-cfg-dollars-brace.patch"
  apply_patch "$PATCH_DIR/php-cfg-mixed-reserved.patch"
  apply_patch "$PATCH_DIR/php-cfg-nullsafe.patch"
  apply_patch "$PATCH_DIR/php-cfg-nullsafe-parser.patch"
  apply_patch "$PATCH_DIR/php-cfg-error-suppress-read.patch"
  apply_patch "$PATCH_DIR/php-cfg-error-suppress-simplifier.patch"
  apply_patch "$PATCH_DIR/php-cfg-strict-types.patch"
  apply_patch "$PATCH_DIR/php-cfg-trycatch.patch"
  apply_patch "$PATCH_DIR/php-cfg-phi-resolver-null.patch"
  apply_patch "$PATCH_DIR/php-cfg-magic-constants.patch"
  apply_patch "$PATCH_DIR/php-cfg-magic-script-const.patch"
  apply_patch "$PATCH_DIR/php-cfg-magic-line.patch"
  apply_patch "$PATCH_DIR/php-cfg-switch-cond-property.patch"
  apply_patch "$PATCH_DIR/php-cfg-loop-resolver-nested.patch"
  apply_patch "$PATCH_DIR/php-cfg-no-arrow-function.patch"
  apply_patch "$PATCH_DIR/php-cfg-no-closure-preg-replace-callback.patch"
  apply_patch "$PATCH_DIR/php-cfg-property-type.patch"
  apply_patch "$PATCH_DIR/php-cfg-assertion-expr-property.patch"
  apply_patch "$PATCH_DIR/php-cfg-yield-from.patch"
  apply_patch "$PATCH_DIR/php-cfg-incdec-expr.patch"
  apply_patch "$PATCH_DIR/php-cfg-yield-keyed.patch"
  apply_patch "$PATCH_DIR/php-cfg-match.patch"
  apply_patch "$PATCH_DIR/php-cfg-assignop-coalesce.patch"
  apply_patch "$PATCH_DIR/php-cfg-first-class-callable.patch"
  apply_patch "$PATCH_DIR/php-cfg-arrow-function.patch"
  apply_patch "$PATCH_DIR/php-cfg-anonymous-class.patch"
  apply_patch "$PATCH_DIR/php-cfg-enum.patch"
  apply_patch "$PATCH_DIR/php-cfg-enum-implements.patch"
  apply_patch "$PATCH_DIR/php-cfg-enum-class-method.patch"
  apply_patch "$PATCH_DIR/php-cfg-named-args.patch"
  apply_patch "$PATCH_DIR/php-cfg-spread.patch"
  apply_patch "$PATCH_DIR/php-cfg-never-type.patch"
  apply_patch "$PATCH_DIR/php-cfg-intersection-type.patch"
  apply_patch "$PATCH_DIR/php-cfg-instanceof-union.patch"
  apply_patch "$PATCH_DIR/php-cfg-union-type.patch"
  apply_patch "$PATCH_DIR/php-cfg-ctor-promotion.patch"
  apply_patch "$PATCH_DIR/php-cfg-attribute-groups.patch"
  apply_patch "$PATCH_DIR/php-cfg-trait-use.patch"
  apply_patch "$PATCH_DIR/php-cfg-throw-expr.patch"
  apply_patch "$PATCH_DIR/php-cfg-is-resource-no-assertion.patch"
fi

if [[ -d "$ROOT/vendor/ircmaxell/php-types" ]]; then
  apply_patch "$PATCH_DIR/php-types-binaryop-pow.patch"
  apply_patch "$PATCH_DIR/php-types-binaryop-coalesce.patch"
  apply_patch "$PATCH_DIR/php-types-cast-object.patch"
  apply_patch "$PATCH_DIR/php-types-cast-unset.patch"
  apply_patch "$PATCH_DIR/php-types-binaryop-spaceship.patch"
  apply_patch "$PATCH_DIR/php-types-str-bool-fns.patch"
  apply_patch "$PATCH_DIR/php-types-str-incdec.patch"
  apply_patch "$PATCH_DIR/php-types-incdec-type.patch"
  apply_patch "$PATCH_DIR/php-types-str-split-string-array.patch"
  apply_patch "$PATCH_DIR/php-types-readfile-int-false.patch"
  apply_patch "$PATCH_DIR/php-types-stream-context-array-return.patch"
  apply_patch "$PATCH_DIR/php-types-strpbrk-string-false.patch"
  apply_patch "$PATCH_DIR/php-types-error-get-last-null.patch"
  apply_patch "$PATCH_DIR/php-types-crc32-int.patch"
  apply_patch "$PATCH_DIR/php-types-dollars-brace.patch"
  apply_patch "$PATCH_DIR/php-types-missing-parent-no-echo.patch"
  apply_patch "$PATCH_DIR/php-types-mixed-reserved.patch"
  apply_patch "$PATCH_DIR/php-types-nullsafe.patch"
  apply_patch "$PATCH_DIR/php-types-static-var.patch"
  apply_patch "$PATCH_DIR/php-types-nullable-return.patch"
  apply_patch "$PATCH_DIR/php-types-cfg-reference.patch"
  apply_patch "$PATCH_DIR/php-types-nullable-optype-return.patch"
  apply_patch "$PATCH_DIR/php-types-yield-from.patch"
  apply_patch "$PATCH_DIR/php-types-fromvalue-null.patch"
  apply_patch "$PATCH_DIR/php-types-doc-comment-string.patch"
  apply_patch "$PATCH_DIR/php-types-docblock-first-token.patch"
  apply_patch "$PATCH_DIR/php-types-array-shape.patch"
  apply_patch "$PATCH_DIR/php-types-generics-fallback.patch"
  apply_patch "$PATCH_DIR/php-types-generics-list-array.patch"
  apply_patch "$PATCH_DIR/php-types-docblock-trailing-text.patch"
  apply_patch "$PATCH_DIR/php-types-fromdecl-junk-fragments.patch"
  apply_patch "$PATCH_DIR/php-types-ns-func-call.patch"
  apply_patch "$PATCH_DIR/php-types-arrow-function.patch"
  apply_patch "$PATCH_DIR/php-types-closure-unbound-this.patch"
  apply_patch "$PATCH_DIR/php-types-magic-script-const.patch"
  apply_patch "$PATCH_DIR/php-types-first-class-callable.patch"
  apply_patch "$PATCH_DIR/php-types-never-type.patch"
  apply_patch "$PATCH_DIR/php-types-intersection-type.patch"
  apply_patch "$PATCH_DIR/php-types-union-type.patch"
  apply_patch "$PATCH_DIR/php-types-throw-expr.patch"
fi

if [[ -d "$ROOT/vendor/pre/plugin" ]]; then
  apply_patch "$PATCH_DIR/pre-plugin-parser-macros.patch"
  apply_patch "$PATCH_DIR/pre-plugin-autoload-prepend.patch"
fi

if ((${#APPLY_PATCH_FAILURES[@]} > 0)); then
  echo "apply-patches: ${#APPLY_PATCH_FAILURES[@]} patch(es) failed: ${APPLY_PATCH_FAILURES[*]}" >&2
  exit 1
fi
