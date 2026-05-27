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

patch_already_applied() {
  local patch="$1"
  case "$(basename "$patch")" in
    php-llvm-chooser.patch)
      grep -q 'PHP_COMPILER_LLVM_PATH' "$ROOT/vendor/ircmaxell/php-llvm/lib/Chooser.php" 2>/dev/null
      ;;
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
      grep -qF '(@var\s+(.+?)(?:\s*\*\/|\s*$))m' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null \
        && grep -qF '(@return\s+(.+?)(?:\s*\*\/|\s*$))m' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null
      ;;
    php-types-array-shape.patch)
      grep -q "preg_match('/^array\\{/'" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null
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
    php-types-crc32-int.patch)
      grep -q "'crc32' => \['int'" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-llvm-builder-xor.patch)
      grep -q 'function xor(' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/Builder.php" 2>/dev/null
      ;;
    php-llvm-no-closures-array-map.patch)
      grep -q '\\$paramTypes = \\[\\];' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/Context.php" 2>/dev/null \
        && grep -q '\\$valueRefs = \\[\\];' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/Builder.php" 2>/dev/null
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
      grep -q "attrGroups'\] = \$expr->attrGroups" "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
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

apply_php_cfg_enum_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local op="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/Enum_.php"
  local overlay="$PATCH_DIR/overlays/php-cfg"
  if grep -q 'function parseStmt_Enum' "$parser" 2>/dev/null; then
    echo "Skip php-cfg-enum.patch (already applied)"
    return 0
  fi
  mkdir -p "$(dirname "$op")"
  cp "$overlay/Op/Stmt/Enum_.php" "$op"
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
parser_anchor = "        if ($node instanceof Node\\UnionType) {"
parser_insert = """        if ($node instanceof Node\\IntersectionType) {
            $types = [];
            foreach ($node->types as $sub) {
                $types[] = $this->parseTypeNode($sub);
            }

            return new Op\\Type\\Intersection($types, $this->mapAttributes($node));
        }
        """
if parser_anchor not in parser:
    sys.stderr.write("php-cfg-intersection-type: UnionType anchor not found in Parser.php\n")
    raise SystemExit(1)
parser = parser.replace(
    parser_anchor,
    parser_insert + parser_anchor,
    1,
)
parser_path.write_text(parser)
printer = printer_path.read_text()
printer_anchor = "        if ($type instanceof Op\\Type\\Literal) {"
printer_insert = """        if ($type instanceof Op\\Type\\Intersection) {
            return implode('&', array_map(
                fn (Op\\Type $t) => $this->renderType($t),
                $type->types
            ));
        }
        """
if printer_anchor not in printer:
    sys.stderr.write("php-cfg-intersection-type: Literal anchor not found in Printer.php\n")
    raise SystemExit(1)
printer = printer.replace(printer_anchor, printer_insert + printer_anchor, 1)
printer_path.write_text(printer)
PY
  echo "Applied php-cfg-intersection-type.patch (overlay)"
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
old = """        if ($decl instanceof CfgType\\Reference) {
            if ($decl->declaration instanceof \\PHPCfg\\Operand\\Literal) {
                return self::fromDecl($decl->declaration->value);
            }

            return self::mixed();
        }

        if ($decl instanceof CfgType\\Union_) {"""
new = """        if ($decl instanceof CfgType\\Reference) {
            if ($decl->declaration instanceof \\PHPCfg\\Operand\\Literal) {
                return self::fromDecl($decl->declaration->value);
            }

            return self::mixed();
        }
        if ($decl instanceof CfgType\\Intersection) {
            $subs = [];
            foreach ($decl->types as $sub) {
                $subs[] = self::fromTypeDecl($sub);
            }

            return new self(self::TYPE_INTERSECTION, $subs);
        }

        if ($decl instanceof CfgType\\Union_) {"""
if old not in text:
    sys.stderr.write("php-types-intersection-type: Type.php anchor not found\n")
    raise SystemExit(1)
path.write_text(text.replace(old, new, 1))
PY
  echo "Applied php-types-intersection-type.patch (overlay)"
}

apply_php_types_first_class_callable_overlay() {
  local target="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
  if grep -q 'Expr_FirstClassCallable' "$target" 2>/dev/null; then
    echo "Skip php-types-first-class-callable.patch (already applied)"
    return 0
  fi
  python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
old = """            case 'Expr_Yield':
            case 'Expr_Include':
                // TODO: we may be able to determine these...
                return false;
            case 'Expr_MagicScriptConst':"""
new = """            case 'Expr_Yield':
            case 'Expr_Include':
                // TODO: we may be able to determine these...
                return false;
            case 'Expr_FirstClassCallable':
                if (\\PHPCfg\\Op\\Expr\\FirstClassCallable::KIND_METHOD === $op->kind) {
                    return [Type::array()];
                }

                return [Type::string()];
            case 'Expr_MagicScriptConst':"""
if old not in text:
    sys.stderr.write("php-types-first-class-callable: TypeReconstructor anchor not found\n")
    raise SystemExit(1)
path.write_text(text.replace(old, new, 1))
PY
  echo "Applied php-types-first-class-callable.patch (overlay)"
}

apply_php_types_magic_script_const_overlay() {
  local target="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
  if grep -q 'Expr_MagicScriptConst' "$target" 2>/dev/null; then
    echo "Skip php-types-magic-script-const.patch (already applied)"
    return 0
  fi
  python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
old = """            case 'Expr_Yield':
            case 'Expr_Include':
                // TODO: we may be able to determine these...
                return false;
        }

        throw new \\LogicException('Unknown variable op found: '.$op->getType());"""
new = """            case 'Expr_Yield':
            case 'Expr_Include':
                // TODO: we may be able to determine these...
                return false;
            case 'Expr_MagicScriptConst':
                if (\\PHPCfg\\Op\\Expr\\MagicScriptConst::KIND_LINE === $op->kind) {
                    return [Type::int()];
                }

                return [Type::string()];
        }

        throw new \\LogicException('Unknown variable op found: '.$op->getType());"""
if old not in text:
    sys.stderr.write("php-types-magic-script-const: TypeReconstructor anchor not found\n")
    raise SystemExit(1)
path.write_text(text.replace(old, new, 1))
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
  if patch_already_applied "$PATCH_DIR/php-cfg-magic-constants.patch"; then
    echo "Skip php-cfg-magic-constants.patch (already applied)"
    return 0
  fi
  if [[ ! -f "$overlay" ]]; then
    echo "Skip php-cfg-magic-constants.patch (overlay missing)" >&2
    return 1
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
  if [[ "$(basename "$patch")" == "php-cfg-intersection-type.patch" ]]; then
    apply_php_cfg_intersection_type_overlay
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
  if [[ "$(basename "$patch")" == "php-types-magic-script-const.patch" ]]; then
    apply_php_types_magic_script_const_overlay
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
apply_patch "$PATCH_DIR/php-llvm-context-empty-arrays.patch"
apply_patch "$PATCH_DIR/php-llvm-makearray-empty.patch"
apply_patch "$PATCH_DIR/php-llvm-builder-select.patch"
apply_patch "$PATCH_DIR/php-llvm-value-addincoming.patch"
apply_patch "$PATCH_DIR/php-llvm-llvmabstract-value-addincoming.patch"
apply_patch "$PATCH_DIR/php-llvm-builder-and-or.patch"
apply_patch "$PATCH_DIR/php-llvm-builder-xor.patch"
apply_patch "$PATCH_DIR/php-llvm-no-closures-array-map.patch"
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
  apply_patch "$PATCH_DIR/php-types-fromdecl-junk-fragments.patch"
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

if ((${#APPLY_PATCH_FAILURES[@]} > 0)); then
  echo "apply-patches: ${#APPLY_PATCH_FAILURES[@]} patch(es) failed: ${APPLY_PATCH_FAILURES[*]}" >&2
  exit 1
fi
