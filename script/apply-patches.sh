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
      grep -qF "(@var\\s+(.+?)(?:\\s*\\*\\/|\\s*$))m" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null \
        && grep -qF "(@return\\s+(.+?)(?:\\s*\\*\\/|\\s*$))m" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null
      ;;
    php-types-array-shape.patch)
      grep -qF "preg_match('/array\\\\{/i', \$decl)" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null \
        && ! grep -qF "preg_match('/\\^array\\\\{/i', \$decl)" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null
      ;;
    php-types-iterable-generic.patch)
      grep -qE "preg_match\('/\^\(list\|array\|iterable\)" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null
      ;;
    php-types-generics-fallback.patch)
      grep -q "non-empty-string" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null
      ;;
    php-types-generics-list-array.patch)
      grep -qE "preg_match\('/\^\(list\|array" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null \
        || grep -qF "preg_match('/^(list|array|iterable)" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null
      ;;
    php-types-generic-null-tail.patch)
      grep -q 'list<T|null> union splits' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null
      ;;
    php-types-docblock-trailing-text.patch)
      grep -q "stripTrailingDocText" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null
      ;;
    php-types-fromdecl-junk-fragments.patch)
      grep -q 'Malformed phpdoc fragments in vendor trees' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null
      ;;
    php-types-fromdecl-trailing-comma.patch)
      grep -q 'Docblock union splits may leave a lone' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null \
        && ! php_types_type_fromdecl_trailing_comma_corrupt "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php"
      ;;
    php-types-remove-type-empty-union.patch)
      ! grep -q "throw new \\\\LogicException('Unknown type encountered')" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null
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
    php-types-get-meta-tags-array-false.patch)
      grep -q "'get_meta_tags' => \['array|false'" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-types-array-combine-array-false.patch)
      grep -qF "'array_combine' => ['array|false'" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
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
    php-types-get-declared-functions.patch)
      grep -q "'get_declared_functions' => \['array'" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-types-gettimeofday-float.patch)
      grep -q "'gettimeofday' => \[''" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-types-round-float.patch)
      grep -q "'round' => \['float'" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-types-link-bool.patch)
      grep -q "'link' => \['bool'" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-types-gc-enabled-bool.patch)
      grep -q "'gc_enabled' => \['bool'\]" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-llvm-builder-xor.patch)
      grep -q 'function xor(' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/Builder.php" 2>/dev/null
      ;;
    php-llvm-no-closures-array-map.patch)
      grep -q 'foreach (\$parameters as \$type)' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/Context.php" 2>/dev/null \
        && grep -q 'foreach (\$elements as \$type)' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/Type/Struct.php" 2>/dev/null \
        && ! grep -q 'array_map(' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/Context.php" 2>/dev/null
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
    php-llvm-vector-get-address-space.patch)
      grep -q 'function getAddressSpace' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/Type/Vector.php" 2>/dev/null
      ;;
    php-llvm-token-type-kind-typo.patch)
      grep -q 'LLVMTokenTypeKind' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/Type.php" 2>/dev/null \
        && ! grep -q 'LLVMTokenTypeKin' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/Type.php" 2>/dev/null
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
      grep -q 'traitStack' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/AstVisitor/MagicStringResolver.php" 2>/dev/null
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
    php-cfg-loop-resolver-continue-switch-warning.patch)
      grep -q 'compiler_language_warning' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/AstVisitor/LoopResolver.php" 2>/dev/null
      ;;
    php-cfg-loop-resolver-break-outside-context.patch)
      grep -q "not in the 'loop' or 'switch' context" "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/AstVisitor/LoopResolver.php" 2>/dev/null
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
    php-cfg-typed-class-const.patch)
      grep -q 'public ?Type \\$declaredType' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Terminal/Const_.php" 2>/dev/null
      ;;
    php-cfg-class-const-flags.patch)
      grep -q 'public int \\$flags = 0' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Terminal/Const_.php" 2>/dev/null
      ;;
    php-cfg-yield-from.overlay)
      grep -q 'function parseExpr_YieldFrom' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
        && [[ -f "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/YieldFrom.php" ]]
      ;;
    php-cfg-asymmetric-visibility.patch)
      grep -q 'public int \$setVisibility' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/Property.php" 2>/dev/null \
        && grep -q 'promotionSetVisibility' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Param.php" 2>/dev/null
      ;;
    php-cfg-assertion-expr-property.patch)
      grep -q 'public \\$expr;' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Assertion.php" 2>/dev/null
      ;;
    php-cfg-match.patch)
      grep -q 'function parseExpr_Match' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
        && grep -q 'lowerUnhandledMatchError' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
        && grep -q 'phpc_match_unhandled_operand_is_object' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-incdec-expr.patch)
      grep -q 'new Op\\Expr\\PostInc' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
        && [[ -f "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/PostInc.php" ]]
      ;;
    php-cfg-halt-compiler.patch)
      grep -q 'new Op\\Stmt\\HaltCompiler' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-assignop-coalesce.patch)
      grep -q "'Expr_AssignOp_Coalesce'" "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-list-destruct-byref.patch)
      grep -q 'if (\$item->byRef)' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
        && grep -A3 'parseListAssignment' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
          | grep -q 'AssignRef'
      ;;
    php-cfg-empty-list-assignment.patch)
      grep -q 'isEmptyListExpr' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
        && grep -q "Cannot use empty list" "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-list-skip-slot.patch)
      grep -A3 'null === $item' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
        | grep -q '++$logicalIndex'
      ;;
    php-cfg-list-spread.patch)
      grep -q 'listSpreadRhs' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Assign.php" 2>/dev/null \
        && grep -q '\$item->unpack' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-first-class-callable.patch)
      grep -q 'isFirstClassCallable' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-new-first-class-callable.patch)
      grep -q 'KIND_NEW' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/FirstClassCallable.php" 2>/dev/null \
        && grep -q 'FirstClassCallable::KIND_NEW' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-arrow-function.patch)
      grep -q 'function parseExpr_ArrowFunction' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-anonymous-class.patch)
      grep -q 'parseStmt_Class($expr->class)' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-new-ctor-parens.patch)
      grep -q 'newHasCtorParens' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
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
    php-cfg-enum-class-const.patch)
      grep -q 'public bool \\$isEnumCase = false' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Terminal/Const_.php" 2>/dev/null \
        && grep -q 'Stmt\\ClassConst' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
        && grep -A30 'function parseStmt_Enum' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null | grep -q 'ClassConst'
      ;;
    php-cfg-enum-trait-use.patch)
      grep -q 'function parseStmt_Enum' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
        && grep -A35 'function parseStmt_Enum' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null | grep -q 'Stmt\\TraitUse'
      ;;
    php-cfg-enum-abstract.patch)
      php_cfg_enum_flags_parser_applied
      ;;
    php-cfg-named-args.patch)
      grep -q 'callArgName' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Operand.php" 2>/dev/null \
        && grep -q 'callArgName' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
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
      grep -q '\$p->promotionFlags = \$param->flags' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-ctor-promotion-readonly.patch)
      grep -q '\$p->promotionReadonly' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-readonly-function.patch)
      grep -q 'FLAG_READONLY' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Func.php" 2>/dev/null \
        && grep -A25 'function parseExpr_Closure' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
          | grep -q "compilerReadonlyFunction" \
        && { ! grep -q 'function parseExpr_ArrowFunction' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
          || grep -A25 'function parseExpr_ArrowFunction' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
            | grep -q "compilerReadonlyFunction"; } \
        && grep -A12 'function parseStmt_Function' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
          | grep -q "compilerReadonlyFunction"
      ;;
    php-cfg-property-readonly.patch)
      grep -qE 'propertyFlags = \$node->flags|\$cfgProp->readonly =|\$prop->readonly =|\$property->readonly =|->readonly = 0 !== \\(\\$node->flags & .*MODIFIER_READONLY\\)' \
        "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-attribute-groups.patch)
      grep -q "attrGroups'\] = \$expr->attrGroups" "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-trait-use.patch)
      [[ -f "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/TraitUse.php" ]] \
        && ! grep -A8 'function parseStmt_TraitUse' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null | grep -q '// TODO'
      ;;
    php-cfg-throw-expr.patch)
      grep -q 'return new Op\\Expr\\Throw_' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
        || grep -q 'parseExpr_Throw' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
        || [[ -f "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Throw_.php" ]]
      ;;
    php-cfg-is-resource-no-assertion.patch)
      ! grep -q "'is_resource' => 'resource'" "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-types-never-type.patch)
      grep -q 'function never(): self' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null \
        && grep -q 'instanceof CfgType\\Never_' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null \
        && grep -q "case 'never':" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null \
        && grep -q 'Op\\Type\\Never_' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php" 2>/dev/null
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
  local overlay="$ROOT/patches/overlays/php-cfg/match-parser-methods.php"
  if [[ ! -f "$overlay" ]]; then
    echo "Skip php-cfg-match.patch (overlay missing)" >&2
    return 1
  fi
  # Always run — patch-php-cfg-match.py refreshes stale is_object probes (#7263, #7199).
  if python3 "$ROOT/script/patch-php-cfg-match.py"; then
    if patch_already_applied "$PATCH_DIR/php-cfg-match.patch"; then
      echo "Refreshed php-cfg-match.patch (overlay)"
    else
      echo "Applied php-cfg-match.patch (overlay)"
    fi
    return 0
  fi
  return 1
}

apply_php_cfg_property_type_overlay() {
  local target="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/Property.php"
  if patch_already_applied "$PATCH_DIR/php-cfg-property-type.patch"; then
    echo "Skip php-cfg-property-type.patch (already applied)"
    return 0
  fi
  python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
if "public $type;" not in text:
    text = text.replace(
        "    public $visibility;\n\n    public $static;",
        "    public $visibility;\n\n    /** Inferred type from php-types TypeReconstructor (bootstrap native AOT needs declared field). */\n    public $type;\n\n    public $static;",
        1,
    )
text = text.replace("int $visiblity,", "int $visibility,", 1)
text = text.replace("$this->visiblity = $visiblity;", "$this->visibility = $visibility;", 1)
if "public $type;" not in text or "int $visibility," not in text:
    sys.stderr.write("php-cfg-property-type: Property.php overlay anchors not found\n")
    raise SystemExit(1)
path.write_text(text)
PY
  echo "Applied php-cfg-property-type.patch (overlay)"
}

apply_php_cfg_assignop_coalesce_overlay() {
  local target="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  if patch_already_applied "$PATCH_DIR/php-cfg-assignop-coalesce.patch"; then
    echo "Skip php-cfg-assignop-coalesce.patch (already applied)"
    return 0
  fi
  python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
needle = "                'Expr_AssignOp_Pow' => Op\\Expr\\BinaryOp\\Pow::class,\n"
insert = needle + "                'Expr_AssignOp_Coalesce' => Op\\Expr\\BinaryOp\\Coalesce::class,\n"
if "'Expr_AssignOp_Coalesce'" in text:
    raise SystemExit(0)
if needle not in text:
    sys.stderr.write("php-cfg-assignop-coalesce: Parser.php AssignOp_Pow anchor not found\n")
    raise SystemExit(1)
path.write_text(text.replace(needle, insert, 1))
PY
  echo "Applied php-cfg-assignop-coalesce.patch (overlay)"
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

apply_php_cfg_process_assertions_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local overlay="$PATCH_DIR/overlays/php-cfg/assertion-parser-methods.php"
  if grep -q 'function processAssertions' "$parser" 2>/dev/null; then
    echo "Skip php-cfg processAssertions overlay (already applied)"
    return 0
  fi
  if [[ ! -f "$parser" || ! -f "$overlay" ]]; then
    echo "Skip php-cfg processAssertions overlay (files missing)" >&2
    return 1
  fi
  python3 - "$parser" "$overlay" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
method_path = Path(sys.argv[2])
text = parser_path.read_text()
if 'function processAssertions' in text:
    raise SystemExit(0)
anchor = "    protected function readAssertion(Assertion $assert)"
insert = method_path.read_text().rstrip("\n") + "\n\n"
if anchor not in text:
    sys.stderr.write("php-cfg-process-assertions: readAssertion anchor not found in Parser.php\n")
    raise SystemExit(1)
parser_path.write_text(text.replace(anchor, insert + anchor, 1))
PY
  echo "Applied php-cfg processAssertions overlay"
}

apply_php_cfg_trait_use_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local op="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/TraitUse.php"
  local overlay="$PATCH_DIR/overlays/php-cfg"
  if [[ ! -f "$parser" || ! -f "$overlay/trait-use-parser-method.php" || ! -f "$overlay/Op/Stmt/TraitUse.php" ]]; then
    echo "Skip php-cfg trait-use overlay (files missing)" >&2
    return 0
  fi
  if grep -q 'function parseStmt_TraitUse' "$parser" 2>/dev/null \
    && ! grep -A8 'function parseStmt_TraitUse' "$parser" 2>/dev/null | grep -q '// TODO'; then
    echo "Skip php-cfg trait-use overlay (already applied)"
    return 0
  fi
  mkdir -p "$(dirname "$op")"
  cp "$overlay/Op/Stmt/TraitUse.php" "$op"
  python3 - "$parser" "$overlay/trait-use-parser-method.php" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
method_path = Path(sys.argv[2])
text = parser_path.read_text()
if 'function parseStmt_TraitUse' in text and '// TODO' not in text.split('function parseStmt_TraitUse', 1)[1].split('function ', 1)[0]:
    raise SystemExit(0)
old = """    protected function parseStmt_TraitUse(Stmt\\TraitUse $node)
    {
        // TODO
    }"""
new = method_path.read_text().rstrip("\n") + "\n"
if old not in text:
    sys.stderr.write("php-cfg-trait-use: parseStmt_TraitUse TODO stub not found in Parser.php\n")
    raise SystemExit(1)
parser_path.write_text(text.replace(old, new, 1))
PY
  echo "Applied php-cfg trait-use overlay (#7417)"
}

apply_php_cfg_yield_from_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local op="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/YieldFrom.php"
  local overlay="$PATCH_DIR/overlays/php-cfg"
  if patch_already_applied "$PATCH_DIR/php-cfg-yield-from.overlay"; then
    echo "Skip php-cfg yield-from overlay (already applied)"
    return 0
  fi
  if [[ ! -f "$overlay/Op/Expr/YieldFrom.php" || ! -f "$overlay/yield-from-parser-method.php" ]]; then
    echo "Skip php-cfg yield-from overlay (overlay files missing)" >&2
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
  echo "Applied php-cfg yield-from overlay"
}

# Vendor may ship promotionSetVisibility on Param without promotionFlags (#1492 partial vendor).
apply_php_cfg_ctor_promotion_overlay() {
  local param="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Param.php"
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  if [[ ! -f "$param" || ! -f "$parser" ]]; then
    return 0
  fi
  if patch_already_applied "$PATCH_DIR/php-cfg-ctor-promotion.patch"; then
    echo "Skip php-cfg-ctor-promotion.patch (already applied)"
    return 0
  fi
  python3 - "$param" "$parser" <<'PY'
import re
import sys
from pathlib import Path

param_path = Path(sys.argv[1])
parser_path = Path(sys.argv[2])
param_text = param_path.read_text()
parser_text = parser_path.read_text()

flags_field = (
    "\n    /** Constructor property promotion visibility (PhpParser Class_ flags), or 0. */\n"
    "    public $promotionFlags = 0;\n"
)
if "promotionFlags" not in param_text:
    for needle in (
        "    /** Constructor promotion: asymmetric set visibility (#3165). */\n",
        "    public int $promotionSetVisibility = 0;\n",
        "    public $promotionSetVisibility = 0;\n",
    ):
        if needle in param_text:
            param_text = param_text.replace(needle, flags_field + needle, 1)
            break
    else:
        needle = "    public $declaredType;\n\n    // A helper\n    public $function;"
        if needle in param_text:
            insert = (
                "    public $declaredType;\n"
                + flags_field
                + "\n    // A helper\n    public $function;"
            )
            param_text = param_text.replace(needle, insert, 1)
        else:
            sys.stderr.write("php-cfg-ctor-promotion: Param.php anchor missing\n")
            raise SystemExit(1)
    param_path.write_text(param_text)

flags_line = "            $p->promotionFlags = $param->flags & Stmt\\Class_::VISIBILITY_MODIFIER_MASK;\n"
if flags_line.strip() not in parser_text:
    inserted = False
    for needle in (
        "            $p->promotionReadonly = 0 !== ($param->flags & Stmt\\Class_::MODIFIER_READONLY);\n",
        "            $p->promotionSetVisibility = $this->extractAsymmetricSetVisibilityFromAttributes($p->getAttributes());\n",
        "            $p->promotionGetVisibility = $this->extractAsymmetricGetVisibilityFromAttributes($p->getAttributes());\n",
        "            $p->result->original = new Operand\\Variable(new Operand\\Literal($p->name->value));\n",
    ):
        if needle in parser_text:
            parser_text = parser_text.replace(needle, flags_line + needle, 1)
            inserted = True
            break
    if not inserted:
        needle = (
            "            );\n"
            "            $p->result->original = new Operand\\Variable(new Operand\\Literal($p->name->value));"
        )
        if needle not in parser_text:
            sys.stderr.write("php-cfg-ctor-promotion: Parser.php parseParameterList anchor missing\n")
            raise SystemExit(1)
        parser_text = parser_text.replace(
            needle,
            "            );\n" + flags_line + "            $p->result->original = new Operand\\Variable(new Operand\\Literal($p->name->value));",
            1,
        )
    parser_path.write_text(parser_text)
PY
  echo "Applied php-cfg-ctor-promotion.patch (overlay)"
}

apply_php_cfg_ctor_promotion_readonly_overlay() {
  local param="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Param.php"
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  if [[ ! -f "$param" || ! -f "$parser" ]]; then
    return 0
  fi
  if patch_already_applied "$PATCH_DIR/php-cfg-ctor-promotion-readonly.patch"; then
    echo "Skip php-cfg-ctor-promotion-readonly.patch (already applied)"
    return 0
  fi
  python3 - "$param" "$parser" <<'PY'
import sys
from pathlib import Path

param_path = Path(sys.argv[1])
parser_path = Path(sys.argv[2])
param_text = param_path.read_text()
parser_text = parser_path.read_text()

readonly_field = (
    "\n    /** Constructor property promotion readonly (PhpParser Class_::MODIFIER_READONLY). */\n"
    "    public $promotionReadonly = false;\n"
)
if "promotionReadonly" not in param_text:
    for needle in (
        "    public $promotionFlags = 0;\n",
        "    public int $promotionFlags = 0;\n",
        "    /** Constructor promotion: asymmetric set visibility (#3165). */\n",
        "    public int $promotionSetVisibility = 0;\n",
    ):
        if needle in param_text:
            param_text = param_text.replace(needle, needle + readonly_field, 1)
            break
    else:
        sys.stderr.write("php-cfg-ctor-promotion-readonly: Param.php anchor missing\n")
        raise SystemExit(1)
    param_path.write_text(param_text)

readonly_line = "            $p->promotionReadonly = 0 !== ($param->flags & Stmt\\Class_::MODIFIER_READONLY);\n"
if readonly_line.strip() not in parser_text:
    flags_line = "            $p->promotionFlags = $param->flags & Stmt\\Class_::VISIBILITY_MODIFIER_MASK;\n"
    if flags_line not in parser_text:
        sys.stderr.write("php-cfg-ctor-promotion-readonly: Parser promotionFlags anchor missing\n")
        raise SystemExit(1)
    parser_text = parser_text.replace(flags_line, flags_line + readonly_line, 1)
    parser_path.write_text(parser_text)
PY
  echo "Applied php-cfg-ctor-promotion-readonly.patch (overlay)"
}

# Vendor may ship promotionSetVisibility on Param before Property gains setVisibility (#3165, #1492).
apply_php_cfg_asymmetric_visibility_overlay() {
  local prop="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/Property.php"
  local param="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Param.php"
  if [[ ! -f "$prop" || ! -f "$param" ]]; then
    return 0
  fi
  local has_prop=0 has_asym_param=0
  if grep -q 'public int \$setVisibility' "$prop" 2>/dev/null; then
    has_prop=1
  fi
  if grep -q 'promotionSetVisibility' "$param" 2>/dev/null; then
    has_asym_param=1
  fi
  if [[ $has_prop -eq 1 && $has_asym_param -eq 1 ]]; then
    if ! grep -q 'public int \$getVisibility' "$prop" 2>/dev/null; then
      python3 - "$prop" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
if 'public int $getVisibility' in text:
    raise SystemExit(0)
needle = "    public int $setVisibility = 0;\n"
insert = needle + "\n    /** PHP 8.4 asymmetric get visibility (0 = same as write; issue #5059). */\n    public int $getVisibility = 0;\n"
if needle not in text:
    sys.stderr.write("php-cfg-asymmetric-visibility: Property.php setVisibility anchor missing\n")
    raise SystemExit(1)
path.write_text(text.replace(needle, insert, 1))
PY
      echo "Applied php-cfg-asymmetric-visibility.patch (Property getVisibility overlay #5059)"
    fi
    if ! grep -q 'promotionGetVisibility' "$param" 2>/dev/null; then
      python3 - "$param" <<'PY'
import re
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
if 'promotionGetVisibility' in text:
    raise SystemExit(0)
get_vis_block = (
    "\n    /** Constructor promotion: asymmetric get visibility (#5059). */\n"
    "    public int $promotionGetVisibility = 0;\n"
)
for needle in (
    "    public int $promotionSetVisibility = 0;\n",
    "    public $promotionSetVisibility = 0;\n",
    "    public int $promotionFlags = 0;\n",
    "    public $promotionFlags = 0;\n",
):
    if needle in text:
        path.write_text(text.replace(needle, needle + get_vis_block, 1))
        raise SystemExit(0)
match = re.search(r"\n    public(?: int)? \$promotion(?:SetVisibility|Flags) = 0;\n", text)
if match is not None:
    needle = match.group(0)
    path.write_text(text.replace(needle, needle + get_vis_block, 1))
    raise SystemExit(0)
sys.stderr.write("php-cfg-asymmetric-visibility: Param.php promotionSetVisibility anchor missing\n")
raise SystemExit(1)
PY
      echo "Applied php-cfg-asymmetric-visibility.patch (Param getVisibility overlay #5059)"
    fi
    if ! grep -q 'promotionSetVisibility' "$param" 2>/dev/null; then
      python3 - "$param" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
if 'promotionSetVisibility' in text:
    raise SystemExit(0)
set_vis_block = (
    "\n    /** Constructor promotion: asymmetric set visibility (#3165). */\n"
    "    public int $promotionSetVisibility = 0;\n"
)
for needle in (
    "    public int $promotionGetVisibility = 0;\n",
    "    public $promotionGetVisibility = 0;\n",
    "    public bool $promotionReadonly = false;\n",
    "    public $promotionReadonly = false;\n",
    "    public int $promotionFlags = 0;\n",
    "    public $promotionFlags = 0;\n",
):
    if needle in text:
        path.write_text(text.replace(needle, needle + set_vis_block, 1))
        raise SystemExit(0)
sys.stderr.write("php-cfg-asymmetric-visibility: Param.php promotionSetVisibility anchor missing for set overlay\n")
raise SystemExit(1)
PY
      echo "Applied php-cfg-asymmetric-visibility.patch (Param setVisibility overlay #8760)"
    fi
    echo "Skip php-cfg-asymmetric-visibility.patch (already applied)"
    return 0
  fi
  if [[ $has_prop -eq 0 ]]; then
    python3 - "$prop" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
if 'public int $setVisibility' in text:
    raise SystemExit(0)
needle = "    public $declaredType;\n"
insert = (
    needle
    + "\n"
    + "    /** PHP 8.4 asymmetric set visibility (0 = same as read; issue #3165). */\n"
    + "    public int $setVisibility = 0;\n"
    + "\n"
    + "    /** PHP 8.4 asymmetric get visibility (0 = same as write; issue #5059). */\n"
    + "    public int $getVisibility = 0;\n"
)
if needle not in text:
    sys.stderr.write("php-cfg-asymmetric-visibility: Property.php declaredType anchor missing\n")
    raise SystemExit(1)
path.write_text(text.replace(needle, insert, 1))
PY
    echo "Applied php-cfg-asymmetric-visibility.patch (Property overlay)"
  fi
  if [[ $has_asym_param -eq 0 ]]; then
    python3 - "$param" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
if 'promotionSetVisibility' in text:
    raise SystemExit(0)
insert_block = (
    "\n"
    + "    /** Constructor promotion: asymmetric set visibility (#3165). */\n"
    + "    public int $promotionSetVisibility = 0;\n"
    + "\n"
    + "    /** Constructor promotion: asymmetric get visibility (#5059). */\n"
    + "    public int $promotionGetVisibility = 0;\n"
)
for needle in (
    "    public bool $promotionReadonly = false;\n",
    "    public $promotionReadonly = false;\n",
):
    if needle in text:
        path.write_text(text.replace(needle, needle + insert_block, 1))
        raise SystemExit(0)
for needle in (
    "    public int $promotionFlags = 0;\n",
    "    public $promotionFlags = 0;\n",
):
    if needle in text:
        path.write_text(text.replace(needle, needle + insert_block, 1))
        raise SystemExit(0)
needle = "    public $declaredType;\n\n    // A helper\n    public $function;"
if needle in text:
    insert = (
        "    public $declaredType;\n\n"
        + "    /** Constructor promotion: asymmetric set visibility (#3165). */\n"
        + "    public int $promotionSetVisibility = 0;\n"
        + "\n"
        + "    /** Constructor promotion: asymmetric get visibility (#5059). */\n"
        + "    public int $promotionGetVisibility = 0;\n\n"
        + "    // A helper\n    public $function;"
    )
    path.write_text(text.replace(needle, insert, 1))
    raise SystemExit(0)
sys.stderr.write("php-cfg-asymmetric-visibility: Param.php anchor missing\n")
raise SystemExit(1)
PY
    echo "Applied php-cfg-asymmetric-visibility.patch (Param overlay)"
  fi
  return 0
}

apply_php_cfg_asymmetric_set_visibility_parser_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local overlay="$PATCH_DIR/overlays/php-cfg/asymmetric-set-visibility-parser-methods.php"
  if [[ ! -f "$parser" || ! -f "$overlay" ]]; then
    return 0
  fi
  if grep -q 'function extractAsymmetricSetVisibilityFromAttributes' "$parser" 2>/dev/null; then
    return 0
  fi
  python3 - "$parser" "$overlay" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
method_path = Path(sys.argv[2])
text = parser_path.read_text()
if 'function extractAsymmetricSetVisibilityFromAttributes' in text:
    raise SystemExit(0)

anchor = """    protected function parseExpr_Yield(Expr\\Yield_ $expr)
    {"""
if anchor not in text:
    sys.stderr.write("php-cfg-asymmetric-set-visibility: parseExpr_Yield anchor not found in Parser.php\n")
    raise SystemExit(1)

insert = method_path.read_text().rstrip("\n") + "\n\n"
text = text.replace(anchor, insert + anchor, 1)

param_needles = [
    "            $p->promotionReadonly = (bool) ($param->flags & Stmt\\Class_::MODIFIER_READONLY);\n",
    "            $p->promotionReadonly = 0 !== ($param->flags & Stmt\\Class_::MODIFIER_READONLY);\n",
]
param_insert_suffix = "            $p->promotionSetVisibility = $this->extractAsymmetricSetVisibilityFromAttributes($p->getAttributes());\n"
for param_needle in param_needles:
    if param_needle in text and 'promotionSetVisibility = $this->extractAsymmetricSetVisibilityFromAttributes' not in text:
        text = text.replace(param_needle, param_needle + param_insert_suffix, 1)
        break

prop_needles = [
    "            $prop->propertyFlags = $node->flags;\n",
    "            $cfgProp->readonly = 0 !== ($node->flags & Node\\Stmt\\Class_::MODIFIER_READONLY);\n",
    "            $prop->readonly = 0 !== ($node->flags & Node\\Stmt\\Class_::MODIFIER_READONLY);\n",
    "            $property->readonly = 0 !== ($node->flags & Node\\Stmt\\Class_::MODIFIER_READONLY);\n",
]
prop_insert_suffix = "            $prop->setVisibility = $this->extractAsymmetricSetVisibilityFromAttributes($prop->getAttributes());\n"
for prop_needle in prop_needles:
    if prop_needle in text and 'setVisibility = $this->extractAsymmetricSetVisibilityFromAttributes' not in text:
        if '$prop->propertyFlags' in prop_needle:
            text = text.replace(prop_needle, prop_needle + prop_insert_suffix, 1)
        elif '$cfgProp->readonly' in prop_needle:
            text = text.replace(
                prop_needle,
                prop_needle + "            $cfgProp->setVisibility = $this->extractAsymmetricSetVisibilityFromAttributes($cfgProp->getAttributes());\n",
                1,
            )
        elif '$prop->readonly' in prop_needle:
            text = text.replace(
                prop_needle,
                prop_needle + "            $prop->setVisibility = $this->extractAsymmetricSetVisibilityFromAttributes($prop->getAttributes());\n",
                1,
            )
        else:
            text = text.replace(
                prop_needle,
                prop_needle + "            $property->setVisibility = $this->extractAsymmetricSetVisibilityFromAttributes($property->getAttributes());\n",
                1,
            )
        break

if 'extractAsymmetricSetVisibilityFromAttributes($p->getAttributes())' not in text \
    and 'extractAsymmetricSetVisibilityFromAttributes($prop->getAttributes())' not in text \
    and 'extractAsymmetricSetVisibilityFromAttributes($cfgProp->getAttributes())' not in text \
    and 'extractAsymmetricSetVisibilityFromAttributes($property->getAttributes())' not in text:
    sys.stderr.write("php-cfg-asymmetric-set-visibility: Parser promotion/readonly anchors missing (apply after ctor-promotion)\n")
    raise SystemExit(1)

parser_path.write_text(text)
PY
  if [[ $? -ne 0 ]]; then
    return 1
  fi
  echo "Applied php-cfg asymmetric set-visibility Parser overlay (#4690)"
}

apply_php_cfg_asymmetric_get_visibility_parser_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local overlay="$PATCH_DIR/overlays/php-cfg/asymmetric-get-visibility-parser-methods.php"
  if [[ ! -f "$parser" || ! -f "$overlay" ]]; then
    return 0
  fi
  if grep -q 'function extractAsymmetricGetVisibilityFromAttributes' "$parser" 2>/dev/null; then
    return 0
  fi
  python3 - "$parser" "$overlay" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
method_path = Path(sys.argv[2])
text = parser_path.read_text()
if 'function extractAsymmetricGetVisibilityFromAttributes' in text:
    raise SystemExit(0)

anchor = """    protected function parseExpr_Yield(Expr\\Yield_ $expr)
    {"""
if anchor not in text:
    sys.stderr.write("php-cfg-asymmetric-get-visibility: parseExpr_Yield anchor not found in Parser.php\n")
    raise SystemExit(1)

insert = method_path.read_text().rstrip("\n") + "\n\n"
text = text.replace(anchor, insert + anchor, 1)

param_needles = [
    "            $p->promotionSetVisibility = $this->extractAsymmetricSetVisibilityFromAttributes($p->getAttributes());\n",
]
param_insert_suffix = "            $p->promotionGetVisibility = $this->extractAsymmetricGetVisibilityFromAttributes($p->getAttributes());\n"
for param_needle in param_needles:
    if param_needle in text and 'promotionGetVisibility = $this->extractAsymmetricGetVisibilityFromAttributes' not in text:
        text = text.replace(param_needle, param_needle + param_insert_suffix, 1)
        break

prop_needles = [
    "            $prop->setVisibility = $this->extractAsymmetricSetVisibilityFromAttributes($prop->getAttributes());\n",
    "            $cfgProp->setVisibility = $this->extractAsymmetricSetVisibilityFromAttributes($cfgProp->getAttributes());\n",
    "            $property->setVisibility = $this->extractAsymmetricSetVisibilityFromAttributes($property->getAttributes());\n",
]
for prop_needle in prop_needles:
    if prop_needle in text and 'getVisibility = $this->extractAsymmetricGetVisibilityFromAttributes' not in text:
        var = 'prop'
        if '$cfgProp->' in prop_needle:
            var = 'cfgProp'
        elif '$property->' in prop_needle:
            var = 'property'
        get_line = f"            ${var}->getVisibility = $this->extractAsymmetricGetVisibilityFromAttributes(${var}->getAttributes());\n"
        text = text.replace(prop_needle, prop_needle + get_line, 1)
        break

if 'extractAsymmetricGetVisibilityFromAttributes($p->getAttributes())' not in text \
    and 'extractAsymmetricGetVisibilityFromAttributes($prop->getAttributes())' not in text \
    and 'extractAsymmetricGetVisibilityFromAttributes($cfgProp->getAttributes())' not in text \
    and 'extractAsymmetricGetVisibilityFromAttributes($property->getAttributes())' not in text:
    sys.stderr.write("php-cfg-asymmetric-get-visibility: Parser setVisibility anchors missing (apply after set overlay)\n")
    raise SystemExit(1)

parser_path.write_text(text)
PY
  if [[ $? -ne 0 ]]; then
    return 1
  fi
  echo "Applied php-cfg asymmetric get-visibility Parser overlay (#5059)"
}

apply_php_cfg_incdec_expr_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local overlay="$PATCH_DIR/overlays/php-cfg"
  if grep -q 'new Op\\Expr\\PostInc' "$parser" 2>/dev/null \
    && [[ -f "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/PostInc.php" ]]; then
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
  if ! python3 - "$parser" "$overlay/incdec-parser-methods.php" <<'PY'
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
  then
    echo "ERROR: php-cfg-incdec-expr overlay failed (#6326, #6321)" >&2
    record_patch_failure "php-cfg-incdec-expr.patch" "PostInc Parser.php anchor missing"
    return 1
  fi
  echo "Applied php-cfg-incdec-expr.patch (overlay)"
}

apply_php_cfg_new_first_class_callable_overlay() {
  local fcc="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/FirstClassCallable.php"
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  if grep -q 'KIND_NEW' "$fcc" 2>/dev/null \
    && grep -q 'FirstClassCallable::KIND_NEW' "$parser" 2>/dev/null; then
    echo "Skip php-cfg-new-first-class-callable.patch (already applied)"
    return 0
  fi
  if ! grep -q 'isFirstClassCallable' "$parser" 2>/dev/null; then
    echo "ERROR: php-cfg-new-first-class-callable requires php-cfg-first-class-callable (#9767)" >&2
    record_patch_failure "php-cfg-new-first-class-callable.patch" "isFirstClassCallable missing"
    return 1
  fi
  if ! python3 - "$fcc" "$parser" <<'PY'
import sys
from pathlib import Path

fcc_path = Path(sys.argv[1])
parser_path = Path(sys.argv[2])
fcc_text = fcc_path.read_text()
parser_text = parser_path.read_text()

if 'KIND_NEW' in fcc_text and 'FirstClassCallable::KIND_NEW' in parser_text:
    raise SystemExit(0)

fcc_old_comment = "/** PHP 8.1+ first-class callable: `foo(...)`, `Class::m(...)`, `$obj->m(...)` (#1230). */"
fcc_new_comment = "/** PHP 8.1+ first-class callable: `foo(...)`, `Class::m(...)`, `$obj->m(...)`, `new C(...)` (#1230, #9767). */"
if fcc_old_comment in fcc_text:
    fcc_text = fcc_text.replace(fcc_old_comment, fcc_new_comment, 1)
elif fcc_new_comment not in fcc_text:
    sys.stderr.write("php-cfg-new-first-class-callable: FirstClassCallable.php comment anchor not found\n")
    raise SystemExit(1)

kind_method = "    public const KIND_METHOD = 3;"
kind_new = """    public const KIND_METHOD = 3;
    public const KIND_NEW = 4;"""
if kind_new not in fcc_text:
    if kind_method not in fcc_text:
        sys.stderr.write("php-cfg-new-first-class-callable: KIND_METHOD anchor not found\n")
        raise SystemExit(1)
    fcc_text = fcc_text.replace(kind_method, kind_new, 1)

fcc_block = """        if ($this->isFirstClassCallable($expr->args)) {
            if ($expr->class instanceof Stmt\\Class_) {
                $this->parseStmt_Class($expr->class);
                $class = $this->readVariable($this->parseExprNode($expr->class->namespacedName));
            } else {
                $class = $this->readVariable($this->parseExprNode($expr->class));
            }

            return new Op\\Expr\\FirstClassCallable(
                Op\\Expr\\FirstClassCallable::KIND_NEW,
                $class,
                $class,
                null,
                $this->mapAttributes($expr)
            );
        }

"""

if 'FirstClassCallable::KIND_NEW' in parser_text:
    fcc_path.write_text(fcc_text)
    raise SystemExit(0)

anchors = [
    """    protected function parseExpr_New(Expr\\New_ $expr)
    {
""",
]
for anchor in anchors:
    if anchor in parser_text and 'FirstClassCallable::KIND_NEW' not in parser_text:
        parser_text = parser_text.replace(anchor, anchor + fcc_block, 1)
        fcc_path.write_text(fcc_text)
        parser_path.write_text(parser_text)
        raise SystemExit(0)

sys.stderr.write("php-cfg-new-first-class-callable: parseExpr_New anchor not found\n")
raise SystemExit(1)
PY
  then
    echo "ERROR: php-cfg-new-first-class-callable overlay failed (#9931, #9767)" >&2
    record_patch_failure "php-cfg-new-first-class-callable.patch" "parseExpr_New anchor missing"
    return 1
  fi
  echo "Applied php-cfg-new-first-class-callable.patch (overlay)"
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
  local parser="${1:-$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php}"
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

apply_php_cfg_enum_trait_use_parser_fix() {
  local parser="${1:-$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php}"
  if grep -A35 'function parseStmt_Enum' "$parser" 2>/dev/null | grep -q 'Stmt\\TraitUse'; then
    return 0
  fi
  if ! grep -q 'function parseStmt_Enum' "$parser" 2>/dev/null; then
    echo "Skip php-cfg-enum-trait-use.patch (parseStmt_Enum missing)" >&2
    return 1
  fi
  python3 - "$parser" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
text = parser_path.read_text()
trait_branch = """            } elseif ($stmt instanceof Stmt\\TraitUse) {
                $this->parseStmt_TraitUse($stmt);
"""
if "Stmt\\TraitUse" in text.split("function parseStmt_Enum", 1)[-1].split("function parseEnumCase", 1)[0]:
    raise SystemExit(0)

with_class_const = """            } elseif ($stmt instanceof Stmt\\ClassMethod) {
                $this->parseStmt_ClassMethod($stmt);
            } elseif ($stmt instanceof Stmt\\ClassConst) {
                $this->parseStmt_ClassConst($stmt);
            }"""
with_class_const_trait = """            } elseif ($stmt instanceof Stmt\\ClassMethod) {
                $this->parseStmt_ClassMethod($stmt);
            } elseif ($stmt instanceof Stmt\\TraitUse) {
                $this->parseStmt_TraitUse($stmt);
            } elseif ($stmt instanceof Stmt\\ClassConst) {
                $this->parseStmt_ClassConst($stmt);
            }"""
without_class_const = """            } elseif ($stmt instanceof Stmt\\ClassMethod) {
                $this->parseStmt_ClassMethod($stmt);
            }
        }
        $this->block = $savedBlock;"""
without_class_const_trait = """            } elseif ($stmt instanceof Stmt\\ClassMethod) {
                $this->parseStmt_ClassMethod($stmt);
            } elseif ($stmt instanceof Stmt\\TraitUse) {
                $this->parseStmt_TraitUse($stmt);
            }
        }
        $this->block = $savedBlock;"""

if with_class_const in text:
    text = text.replace(with_class_const, with_class_const_trait, 1)
elif without_class_const in text:
    text = text.replace(without_class_const, without_class_const_trait, 1)
else:
    sys.stderr.write("php-cfg-enum-trait-use: parseStmt_Enum loop anchor not found\n")
    raise SystemExit(1)
parser_path.write_text(text)
PY
  echo "Applied php-cfg-enum-trait-use.patch (overlay)"
}

apply_php_cfg_enum_class_const_overlay() {
  local const_file="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Terminal/Const_.php"
  local parser="${1:-$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php}"
  local already_applied=0
  if patch_already_applied "$PATCH_DIR/php-cfg-enum-class-const.patch"; then
    already_applied=1
  fi
  if ! grep -q 'function parseStmt_Enum' "$parser" 2>/dev/null; then
    echo "Skip php-cfg-enum-class-const.patch (parseStmt_Enum missing)" >&2
    return 1
  fi
  if [[ "$already_applied" -eq 1 ]] \
    && grep -q 'enumCaseHasExplicitValue' "$const_file" 2>/dev/null \
    && grep -q 'enumCaseHasExplicitValue' "$parser" 2>/dev/null; then
    return 0
  fi
  python3 - "$const_file" "$parser" <<'PY'
import sys
from pathlib import Path

const_path = Path(sys.argv[1])
parser_path = Path(sys.argv[2])
const_text = const_path.read_text()
if "isEnumCase" not in const_text:
    insert = (
        "    /** True for `case Name = value` in enums; false for `const` in enum/class bodies (#5054). */\n"
        "    public bool $isEnumCase = false;\n\n"
        "    /** True when enum case declares `= value`; false for unit enum implicit case (#5397). */\n"
        "    public bool $enumCaseHasExplicitValue = false;\n\n"
    )
    for old in (
        "    public int $flags = 0;\n\n    public function __construct",
        "    public bool $enumCaseHasExplicitValue = false;\n\n    public function __construct",
        "    public bool $isEnumCase = false;\n\n    public function __construct",
        "    public ?Type $declaredType = null;\n\n    public function __construct",
        "    public $valueBlock;\n\n    public function __construct",
    ):
        if old in const_text:
            const_path.write_text(
                const_text.replace(old, old.replace("\n\n    public function __construct", "\n\n" + insert + "    public function __construct", 1), 1)
            )
            break
    else:
        sys.stderr.write("php-cfg-enum-class-const: Const_.php anchor not found\n")
        raise SystemExit(1)

text = parser_path.read_text()
enum_loop_old = """            } elseif ($stmt instanceof Stmt\\ClassMethod) {
                $this->parseStmt_ClassMethod($stmt);
            }
        }
        $this->block = $savedBlock;"""
enum_loop_new = """            } elseif ($stmt instanceof Stmt\\ClassMethod) {
                $this->parseStmt_ClassMethod($stmt);
            } elseif ($stmt instanceof Stmt\\ClassConst) {
                $this->parseStmt_ClassConst($stmt);
            }
        }
        $this->block = $savedBlock;"""
if "Stmt\\ClassConst" not in text.split("function parseStmt_Enum", 1)[-1].split("function parseEnumCase", 1)[0]:
    if enum_loop_old not in text:
        sys.stderr.write("php-cfg-enum-class-const: parseStmt_Enum loop anchor not found\n")
        raise SystemExit(1)
    text = text.replace(enum_loop_old, enum_loop_new, 1)

case_old = """        $this->block->children[] = new Op\\Terminal\\Const_(
            $this->parseExprNode($node->name),
            $value,
            $valueBlock,
            $this->mapAttributes($node)
        );"""
case_new = """        $constOp = new Op\\Terminal\\Const_(
            $this->parseExprNode($node->name),
            $value,
            $valueBlock,
            $this->mapAttributes($node)
        );
        $constOp->isEnumCase = true;
        $constOp->enumCaseHasExplicitValue = null !== $node->expr;
        $this->block->children[] = $constOp;"""
if case_old in text:
    text = text.replace(case_old, case_new, 1)
elif "enumCaseHasExplicitValue" not in text.split("function parseEnumCase", 1)[-1].split("function parseStmt_Echo", 1)[0]:
    case_is_only = """        $constOp->isEnumCase = true;
        $this->block->children[] = $constOp;"""
    case_is_explicit = """        $constOp->isEnumCase = true;
        $constOp->enumCaseHasExplicitValue = null !== $node->expr;
        $this->block->children[] = $constOp;"""
    if case_is_only in text:
        text = text.replace(case_is_only, case_is_explicit, 1)

const_fresh = const_path.read_text()
if "enumCaseHasExplicitValue" not in const_fresh and "isEnumCase" in const_fresh:
    const_text = const_fresh.replace(
        "    public bool $isEnumCase = false;\n\n",
        "    public bool $isEnumCase = false;\n\n"
        "    /** True when enum case declares `= value`; false for unit enum implicit case (#5397). */\n"
        "    public bool $enumCaseHasExplicitValue = false;\n\n",
        1,
    )
    const_path.write_text(const_text)

parser_path.write_text(text)
PY
  if [[ "$already_applied" -eq 0 ]]; then
    echo "Applied php-cfg-enum-class-const.patch (overlay)"
  else
    echo "Synced php-cfg-enum-class-const overlay (#5397 enumCaseHasExplicitValue)"
  fi
}

apply_php_cfg_enum_class_const_parser_fix() {
  apply_php_cfg_enum_class_const_overlay
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
    php_cfg_sync_enum_flags_parser "$parser" "$op" || true
    apply_php_cfg_enum_trait_use_parser_fix "$parser"
    apply_php_cfg_enum_class_const_parser_fix "$parser"
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
  apply_php_cfg_enum_trait_use_parser_fix "$parser"
  apply_php_cfg_enum_class_const_parser_fix "$parser"
  php_cfg_sync_enum_flags_parser "$parser" "$op" || true
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
  php_cfg_sync_enum_flags_parser "$parser" "$op" || true
}

php_cfg_enum_flags_parser_applied() {
  local parser="${1:-$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php}"
  [[ -f "$parser" ]] || return 1
  grep -A12 'new Op\\Stmt\\Enum_' "$parser" 2>/dev/null | grep -q '\$flags,'
}

php_cfg_enum_op_expects_flags() {
  local op="${1:-$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/Enum_.php}"
  [[ -f "$op" ]] || return 1
  grep -q 'int \$flags' "$op" 2>/dev/null
}

php_cfg_apply_enum_flags_parser_fix() {
  local parser="${1:-$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php}"
  local overlay="$PATCH_DIR/overlays/php-cfg"
  if ! grep -q 'function parseStmt_Enum' "$parser" 2>/dev/null; then
    return 1
  fi
  if php_cfg_enum_flags_parser_applied "$parser"; then
    return 0
  fi
  python3 - "$parser" "$overlay/enum-abstract-parser-method.php" <<'PY'
import re
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
method_path = Path(sys.argv[2])
text = parser_path.read_text()
replacement = method_path.read_text().rstrip("\n") + "\n"
pattern = r"    protected function parseStmt_Enum\(Stmt\\Enum_ \$node\)\s*\{.*?\n    \}\n"
match = re.search(pattern, text, re.S)
if not match:
    sys.stderr.write("php-cfg-enum-abstract: parseStmt_Enum method not found in Parser.php\n")
    raise SystemExit(1)
parser_path.write_text(text[: match.start()] + replacement + text[match.end() :])
PY
}

# Keep Enum_.php flags ctor and parseStmt_Enum in sync (#3114).
php_cfg_sync_enum_flags_parser() {
  local parser="${1:-$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php}"
  local op="${2:-$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/Enum_.php}"
  if ! php_cfg_enum_op_expects_flags "$op"; then
    return 0
  fi
  if php_cfg_enum_flags_parser_applied "$parser"; then
    return 0
  fi
  php_cfg_apply_enum_flags_parser_fix "$parser"
  apply_php_cfg_enum_trait_use_parser_fix "$parser" || true
  echo "Repair php-cfg-enum-abstract.patch (Enum_ flags ctor vs Parser.php)"
}

# Run enum overlays before patches that may fail and abort the php-cfg block (#3114).
apply_php_cfg_enum_early_chain() {
  [[ -d "$ROOT/vendor/ircmaxell/php-cfg" ]] || return 0
  apply_php_cfg_enum_overlay || true
  apply_php_cfg_enum_implements_overlay || true
  apply_php_cfg_enum_class_method_parser_fix || true
  apply_php_cfg_enum_trait_use_parser_fix || true
  apply_php_cfg_enum_class_const_parser_fix || true
  apply_php_cfg_enum_abstract_overlay || true
  php_cfg_sync_enum_flags_parser || true
}

apply_php_cfg_enum_abstract_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local op="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/Enum_.php"
  local overlay="$PATCH_DIR/overlays/php-cfg"
  if ! grep -q 'function parseStmt_Enum' "$parser" 2>/dev/null; then
    echo "Skip php-cfg-enum-abstract.patch (parseStmt_Enum missing)" >&2
    return 1
  fi
  cp "$overlay/Op/Stmt/Enum_.php" "$op"
  if php_cfg_enum_flags_parser_applied "$parser"; then
    echo "Skip php-cfg-enum-abstract.patch (already applied)"
    return 0
  fi
  php_cfg_apply_enum_flags_parser_fix "$parser"
  echo "Applied php-cfg-enum-abstract.patch (overlay)"
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
  if [[ -f "$op" ]] && awk '/protected function parseTypeNode\(/,/throw new \\LogicException\('"'"'Unknown type node:/' "$parser" 2>/dev/null | grep -q 'Node\\UnionType'; then
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
import re

parse_type = re.search(
    r'protected function parseTypeNode\(.*?throw new \\LogicException\(\'Unknown type node:',
    parser,
    re.S,
)
parse_type_body = parse_type.group(0) if parse_type else ''
if 'Node\\UnionType' not in parse_type_body:
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

apply_php_types_intersection_type_reconstructor_overlay_to_target() {
  local target="$1"
  if [[ ! -f "$target" ]]; then
    echo "Skip php-types-intersection-type.patch (target missing): ${target}"
    return 0
  fi
  if grep -q 'instanceof Op\\Type\\Intersection' "$target" 2>/dev/null && php -l "$target" >/dev/null 2>&1; then
    return 0
  fi
  if ! python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
intersection_branch = """        } elseif ($type instanceof Op\\Type\\Intersection) {
            $subs = [];
            foreach ($type->types as $sub) {
                $subs[] = $this->resolveOpType($sub);
            }

            return new Type(Type::TYPE_INTERSECTION, $subs);
"""
throw_anchor = """        throw new \\LogicException('Unknown Op\\\\Type provided: '.get_class($type));"""
union_close = (
    "            return (new Type(Type::TYPE_UNION, $subs))->simplify();\n"
    "        }\n\n"
    + throw_anchor
)
if "instanceof Op\\Type\\Intersection" in text:
    raise SystemExit(0)
if union_close in text:
    text = text.replace(union_close, union_close.replace("\n\n" + throw_anchor, "\n" + intersection_branch + "\n" + throw_anchor, 1), 1)
elif throw_anchor in text:
    text = text.replace(throw_anchor, intersection_branch + "\n" + throw_anchor, 1)
else:
    sys.stderr.write("php-types-intersection-type: TypeReconstructor anchor not found\n")
    raise SystemExit(1)
path.write_text(text)
PY
  then
    echo "ERROR: php-types-intersection-type overlay failed for ${target} (#6820)" >&2
    return 1
  fi
  echo "Applied php-types-intersection-type.patch (TypeReconstructor overlay): ${target}"
}

apply_php_types_intersection_type_type_overlay_to_target() {
  local target="$1"
  if [[ ! -f "$target" ]]; then
    echo "Skip php-types-intersection-type.patch (target missing): ${target}"
    return 0
  fi
  if grep -q 'instanceof CfgType\\Union_' "$target" 2>/dev/null \
    && grep -q 'instanceof CfgType\\Intersection' "$target" 2>/dev/null; then
    return 0
  fi
  if ! python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()

anchor = "        throw new \\LogicException('Unsupported declaration type: '.get_class($decl));"
if anchor not in text:
    sys.stderr.write("php-types-intersection-type: throw anchor not found\n")
    raise SystemExit(1)

union_block = """        if ($decl instanceof CfgType\\Union_) {
            $subs = [];
            foreach ($decl->types as $sub) {
                $subs[] = self::fromTypeDecl($sub);
            }

            return new self(self::TYPE_UNION, $subs);
        }
"""
intersection_block = """        if ($decl instanceof CfgType\\Intersection) {
            $subs = [];
            foreach ($decl->types as $sub) {
                $subs[] = self::fromTypeDecl($sub);
            }

            return new self(self::TYPE_INTERSECTION, $subs);
        }

"""
insert = ""
if "instanceof CfgType\\Union_" not in text:
    insert += union_block
if "instanceof CfgType\\Intersection" not in text:
    insert += intersection_block
if not insert:
    raise SystemExit(0)
path.write_text(text.replace(anchor, insert + anchor, 1))
PY
  then
    echo "ERROR: php-types-intersection-type Type.php overlay failed for ${target} (#6820)" >&2
    return 1
  fi
  echo "Applied php-types-intersection-type.patch (Type.php overlay): ${target}"
}

apply_php_types_intersection_type_overlay() {
  local rc=0
  local vendor_type="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php"
  local prelinked_type="$ROOT/prelinked/bootstrap-vendor/sources/ircmaxell/php-types/lib/PHPTypes/Type.php"
  local vendor_recon="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
  local prelinked_recon="$ROOT/prelinked/bootstrap-vendor/sources/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
  local applied=0

  if [[ -f "$vendor_type" ]] && ! grep -q 'instanceof CfgType\\Intersection' "$vendor_type" 2>/dev/null; then
    apply_php_types_intersection_type_type_overlay_to_target "$vendor_type" || rc=1
    applied=1
  fi
  if [[ -f "$prelinked_type" ]] && ! grep -q 'instanceof CfgType\\Intersection' "$prelinked_type" 2>/dev/null; then
    apply_php_types_intersection_type_type_overlay_to_target "$prelinked_type" || rc=1
    applied=1
  fi
  if ! apply_php_types_intersection_type_reconstructor_overlay_to_target "$vendor_recon"; then
    rc=1
  elif [[ -f "$vendor_recon" ]] \
    && ! grep -q 'instanceof Op\\Type\\Intersection' "$vendor_recon" 2>/dev/null; then
    applied=1
  fi
  if ! apply_php_types_intersection_type_reconstructor_overlay_to_target "$prelinked_recon"; then
    rc=1
  elif [[ -f "$prelinked_recon" ]] \
    && ! grep -q 'instanceof Op\\Type\\Intersection' "$prelinked_recon" 2>/dev/null; then
    applied=1
  fi
  if [[ "$applied" -eq 0 ]]; then
    echo "Skip php-types-intersection-type.patch (already applied)"
  else
    echo "Applied php-types-intersection-type.patch (overlay)"
  fi
  return "$rc"
}

repair_php_types_union_type_reconstructor_at() {
  local target="$1"
  local ssot="$2"
  [[ -f "$target" ]] || return 0
  if php -l "$target" >/dev/null 2>&1; then
    return 0
  fi
  echo "apply-patches: repairing malformed php-types-union-type in ${target} (#4229)" >&2
  if [[ ! -f "$ssot" ]]; then
    echo "apply-patches: missing SSOT ${ssot} for TypeReconstructor repair" >&2
    return 1
  fi
  python3 - "$target" "$ssot" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
ssot = Path(sys.argv[2])
text = path.read_text()
ssot_text = ssot.read_text()
start = text.find("    private function resolveOpType(Op\\Type $type): Type")
end = text.find("    private function resolveMethodCall", start)
ssot_start = ssot_text.find("    private function resolveOpType(Op\\Type $type): Type")
ssot_end = ssot_text.find("    private function resolveMethodCall", ssot_start)
if -1 in (start, end, ssot_start, ssot_end) or end <= start or ssot_end <= ssot_start:
    sys.stderr.write("php-types-union-type-repair: resolveOpType anchors not found\n")
    raise SystemExit(1)
path.write_text(text[:start] + ssot_text[ssot_start:ssot_end] + text[end:])
PY
}

repair_php_types_union_type_reconstructor_if_needed() {
  local vendor_recon="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
  local prelinked_recon="$ROOT/prelinked/bootstrap-vendor/sources/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
  repair_php_types_union_type_reconstructor_at "$vendor_recon" "$prelinked_recon" || return 1
  if [[ -f "$prelinked_recon" ]] && ! php -l "$prelinked_recon" >/dev/null 2>&1; then
    repair_php_types_union_type_reconstructor_at "$prelinked_recon" "$vendor_recon" || return 1
  fi
}

apply_php_types_union_type_reconstructor_overlay_to_target() {
  local target="$1"
  if [[ ! -f "$target" ]]; then
    echo "Skip php-types-union-type.patch (target missing): ${target}"
    return 0
  fi
  if grep -q 'instanceof Op\\Type\\Union_' "$target" 2>/dev/null \
    && grep -q 'instanceof Op\\Type\\Intersection' "$target" 2>/dev/null \
    && php -l "$target" >/dev/null 2>&1; then
    return 0
  fi
  if ! python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
union_body = """            $subs = [];
            foreach ($type->types as $sub) {
                $subs[] = $this->resolveOpType($sub);
            }

            return (new Type(Type::TYPE_UNION, $subs))->simplify();
"""
union_before_intersection = (
    "        } elseif ($type instanceof Op\\Type\\Union_) {\n"
    + union_body
)
if "instanceof Op\\Type\\Union_" in text:
    raise SystemExit(0)

intersection_anchor = """        } elseif ($type instanceof Op\\Type\\Intersection) {"""
never_throw_anchor = """        } elseif ($type instanceof Op\\Type\\Never_) {
            return Type::never();
        }

        throw new \\LogicException('Unknown Op\\\\Type provided: '.get_class($type));"""
literal_throw_anchor = """        } elseif ($type instanceof Op\\Type\\Literal) {
            return Type::fromDecl($type->name);
        }

        throw new \\LogicException('Unknown Op\\\\Type provided: '.get_class($type));"""
intersection_branch = """        } elseif ($type instanceof Op\\Type\\Intersection) {
            $subs = [];
            foreach ($type->types as $sub) {
                $subs[] = $this->resolveOpType($sub);
            }

            return new Type(Type::TYPE_INTERSECTION, $subs);
"""
never_union_tail = (
    """        } elseif ($type instanceof Op\\Type\\Never_) {
            return Type::never();
        } elseif ($type instanceof Op\\Type\\Union_) {
"""
    + union_body
    + intersection_branch
    + """

        throw new \\LogicException('Unknown Op\\\\Type provided: '.get_class($type));"""
)
literal_union_tail = (
    """        } elseif ($type instanceof Op\\Type\\Literal) {
            return Type::fromDecl($type->name);
        } elseif ($type instanceof Op\\Type\\Never_) {
            return Type::never();
        } elseif ($type instanceof Op\\Type\\Union_) {
"""
    + union_body
    + intersection_branch
    + """

        throw new \\LogicException('Unknown Op\\\\Type provided: '.get_class($type));"""
)

if intersection_anchor in text:
    text = text.replace(intersection_anchor, union_before_intersection + intersection_anchor, 1)
elif never_throw_anchor in text:
    text = text.replace(never_throw_anchor, never_union_tail, 1)
elif literal_throw_anchor in text:
    text = text.replace(literal_throw_anchor, literal_union_tail, 1)
else:
    sys.stderr.write(
        "php-types-union-type: TypeReconstructor anchor not found "
        "(expected Intersection handler, Never_/throw tail, or Literal/throw tail)\n"
    )
    raise SystemExit(1)
path.write_text(text)
PY
  then
    echo "ERROR: php-types-union-type overlay failed for ${target} (#6820)" >&2
    return 1
  fi
  echo "Applied php-types-union-type.patch (TypeReconstructor overlay): ${target}"
}

apply_php_types_union_type_overlay() {
  local rc=0
  local vendor_type="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php"
  local prelinked_type="$ROOT/prelinked/bootstrap-vendor/sources/ircmaxell/php-types/lib/PHPTypes/Type.php"
  local vendor_recon="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
  local prelinked_recon="$ROOT/prelinked/bootstrap-vendor/sources/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
  local applied=0

  repair_php_types_union_type_reconstructor_if_needed || rc=1

  if [[ -f "$vendor_type" ]] && ! grep -q 'instanceof CfgType\\Union_' "$vendor_type" 2>/dev/null; then
    apply_php_types_intersection_type_type_overlay_to_target "$vendor_type" || rc=1
    applied=1
  fi
  if [[ -f "$prelinked_type" ]] && ! grep -q 'instanceof CfgType\\Union_' "$prelinked_type" 2>/dev/null; then
    apply_php_types_intersection_type_type_overlay_to_target "$prelinked_type" || rc=1
    applied=1
  fi
  if [[ -f "$vendor_recon" ]] \
    && { ! grep -q 'instanceof Op\\Type\\Union_' "$vendor_recon" 2>/dev/null \
      || ! php -l "$vendor_recon" >/dev/null 2>&1; }; then
    apply_php_types_union_type_reconstructor_overlay_to_target "$vendor_recon" || rc=1
    applied=1
  fi
  if [[ -f "$prelinked_recon" ]] \
    && { ! grep -q 'instanceof Op\\Type\\Union_' "$prelinked_recon" 2>/dev/null \
      || ! php -l "$prelinked_recon" >/dev/null 2>&1; }; then
    apply_php_types_union_type_reconstructor_overlay_to_target "$prelinked_recon" || rc=1
    applied=1
  fi
  if ! apply_php_types_intersection_type_reconstructor_overlay_to_target "$vendor_recon"; then
    rc=1
  fi
  if ! apply_php_types_intersection_type_reconstructor_overlay_to_target "$prelinked_recon"; then
    rc=1
  fi
  if [[ "$applied" -eq 0 ]]; then
    echo "Skip php-types-union-type.patch (already applied)"
  else
    echo "Applied php-types-union-type.patch (overlay)"
  fi
  return "$rc"
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

repair_php_types_fcc_type_array_typo_in_target() {
  local target="$1"
  if [[ ! -f "$target" ]]; then
    return 0
  fi
  if grep -q 'return \[Type::array()\];' "$target" 2>/dev/null; then
    sed -i 's/return \[Type::array()\];/return [new Type(Type::TYPE_ARRAY)];/' "$target"
    echo "Repaired php-types-first-class-callable Type::array() typo in ${target} (#4957, #6932)"
  fi
}

apply_php_types_fcc_overlay_final_repair() {
  repair_php_types_fcc_type_array_typo_in_target "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
  repair_php_types_fcc_type_array_typo_in_target "$ROOT/prelinked/bootstrap-vendor/sources/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
}

apply_php_types_first_class_callable_overlay() {
  local target="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
  repair_php_types_fcc_type_array_typo_in_target "$target"
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
        """            case 'Expr_MagicScriptConst':""",
        fcc_case + """            case 'Expr_MagicScriptConst':""",
    ),
    (
        """            case 'Expr_Yield':
            case 'Expr_YieldFrom':
            case 'Expr_Include':
                // TODO: we may be able to determine these...
                return false;
            case 'Expr_PostInc':""",
        """            case 'Expr_Yield':
            case 'Expr_YieldFrom':
            case 'Expr_Include':
                // TODO: we may be able to determine these...
                return false;
""" + fcc_case + """
            case 'Expr_PostInc':""",
    ),
    (
        """            case 'Expr_Yield':
            case 'Expr_Include':
                // TODO: we may be able to determine these...
                return false;

            case 'Expr_PostInc':""",
        """            case 'Expr_Yield':
            case 'Expr_Include':
                // TODO: we may be able to determine these...
                return false;
""" + fcc_case + """
            case 'Expr_PostInc':""",
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

apply_php_types_magic_script_const_overlay_to_target() {
  local target="$1"
  [[ -f "$target" ]] || return 0
  if grep -q 'MagicScriptConst::KIND_LINE' "$target" 2>/dev/null; then
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
            case 'Expr_FirstClassCallable':
                if (\\PHPCfg\\Op\\Expr\\FirstClassCallable::KIND_METHOD === $op->kind) {
                    return [new Type(Type::TYPE_ARRAY)];
                }

                return [Type::string()];
            case 'Expr_PostInc':""",
        """            case 'Expr_Yield':
            case 'Expr_YieldFrom':
            case 'Expr_Include':
                // TODO: we may be able to determine these...
                return false;
""" + msc_case + """            case 'Expr_FirstClassCallable':
                if (\\PHPCfg\\Op\\Expr\\FirstClassCallable::KIND_METHOD === $op->kind) {
                    return [new Type(Type::TYPE_ARRAY)];
                }

                return [Type::string()];
            case 'Expr_PostInc':""",
    ),
    (
        """            case 'Expr_Yield':
            case 'Expr_YieldFrom':
            case 'Expr_Include':
                // TODO: we may be able to determine these...
                return false;
            case 'Expr_PostInc':""",
        """            case 'Expr_Yield':
            case 'Expr_YieldFrom':
            case 'Expr_Include':
                // TODO: we may be able to determine these...
                return false;
""" + msc_case + """            case 'Expr_PostInc':""",
    ),
    (
        """            case 'Expr_Yield':
            case 'Expr_YieldFrom':
            case 'Expr_Include':
                // TODO: we may be able to determine these...
                return false;

            case 'Expr_PostInc':""",
        """            case 'Expr_Yield':
            case 'Expr_YieldFrom':
            case 'Expr_Include':
                // TODO: we may be able to determine these...
                return false;
""" + msc_case + """
            case 'Expr_PostInc':""",
    ),
    (
        """            case 'Expr_Yield':
            case 'Expr_Include':
                // TODO: we may be able to determine these...
                return false;
            case 'Expr_PostInc':""",
        """            case 'Expr_Yield':
            case 'Expr_Include':
                // TODO: we may be able to determine these...
                return false;
""" + msc_case + """            case 'Expr_PostInc':""",
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
sys.stderr.write("php-types-magic-script-const: TypeReconstructor anchor not found in " + sys.argv[1] + "\n")
raise SystemExit(1)
PY
}

apply_php_types_magic_script_const_overlay() {
  local vendor="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
  local prelinked="$ROOT/prelinked/bootstrap-vendor/sources/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
  if grep -q 'MagicScriptConst::KIND_LINE' "$vendor" 2>/dev/null \
    && { [[ ! -f "$prelinked" ]] || grep -q 'MagicScriptConst::KIND_LINE' "$prelinked" 2>/dev/null; }; then
    echo "Skip php-types-magic-script-const.patch (already applied)"
    return 0
  fi
  apply_php_types_magic_script_const_overlay_to_target "$vendor" \
    || return 1
  apply_php_types_magic_script_const_overlay_to_target "$prelinked" \
    || return 1
  echo "Applied php-types-magic-script-const.patch (overlay)"
}

apply_php_cfg_class_const_flags_overlay() {
  local const_file="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Terminal/Const_.php"
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  if patch_already_applied "$PATCH_DIR/php-cfg-class-const-flags.patch"; then
    echo "Skip php-cfg-class-const-flags.patch (already applied)"
    return 0
  fi
  python3 - "$const_file" "$parser" <<'PY'
import sys
from pathlib import Path

const_path = Path(sys.argv[1])
parser_path = Path(sys.argv[2])
const_text = const_path.read_text()
if 'public int $flags = 0' not in const_text:
    for old in (
        "    public ?Type $declaredType = null;\n\n    public function __construct",
        "    public $valueBlock;\n\n    public function __construct",
    ):
        if old in const_text:
            const_text = const_text.replace(
                old,
                old.replace(
                    "\n\n    public function __construct",
                    "\n\n    /** PhpParser Stmt\\ClassConst flags (MODIFIER_FINAL, visibility, etc.). */\n"
                    "    public int $flags = 0;\n\n    public function __construct",
                    1,
                ),
                1,
            )
            const_path.write_text(const_text)
            break
    else:
        sys.stderr.write("php-cfg-class-const-flags: Const_.php anchor not found\n")
        raise SystemExit(1)

text = parser_path.read_text()
if '$constOp->flags = $node->flags' not in text:
    old = "$constOp->declaredType = null !== $node->type ? $this->parseTypeNode($node->type) : null;\n            $this->block->children[] = $constOp;"
    new = old.replace(
        "\n            $this->block->children[] = $constOp;",
        "\n            $constOp->flags = $node->flags;\n            $this->block->children[] = $constOp;",
        1,
    )
    if old not in text:
        sys.stderr.write("php-cfg-class-const-flags: Parser parseStmt_ClassConst anchor not found\n")
        raise SystemExit(1)
    parser_path.write_text(text.replace(old, new, 1))
PY
  echo "Applied php-cfg-class-const-flags.patch (overlay)"
}

apply_php_types_incdec_type_overlay_to_target() {
  local target="$1"
  if [[ ! -f "$target" ]]; then
    echo "Skip php-types-incdec-type.patch (target missing): ${target}"
    return 0
  fi
  if grep -q "case 'Expr_PostInc':" "$target" 2>/dev/null; then
    echo "Skip php-types-incdec-type.patch (already applied): ${target}"
    return 0
  fi
  if ! python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
incdec_case = """
            case 'Expr_PostInc':
            case 'Expr_PostDec':
            case 'Expr_PreInc':
            case 'Expr_PreDec':
                if ($resolved->contains($op->read)) {
                    return [$resolved[$op->read]];
                }

                return false;
"""
throw_tail = "        throw new \\LogicException('Unknown variable op found: '.$op->getType());"
anchors = [
    (
        """            case 'Expr_Yield':
            case 'Expr_Include':
                // TODO: we may be able to determine these...
                return false;
        }

""" + throw_tail,
        """            case 'Expr_Yield':
            case 'Expr_Include':
                // TODO: we may be able to determine these...
                return false;
""" + incdec_case + """        }

""" + throw_tail,
    ),
    (
        """            case 'Expr_FirstClassCallable':
                if (\\PHPCfg\\Op\\Expr\\FirstClassCallable::KIND_METHOD === $op->kind) {
                    return [new Type(Type::TYPE_ARRAY)];
                }

                return [Type::string()];
            case 'Expr_MagicScriptConst':
                if (\\PHPCfg\\Op\\Expr\\MagicScriptConst::KIND_LINE === $op->kind) {
                    return [Type::int()];
                }

                return [Type::string()];

        }

""" + throw_tail,
        """            case 'Expr_FirstClassCallable':
                if (\\PHPCfg\\Op\\Expr\\FirstClassCallable::KIND_METHOD === $op->kind) {
                    return [new Type(Type::TYPE_ARRAY)];
                }

                return [Type::string()];
            case 'Expr_MagicScriptConst':
                if (\\PHPCfg\\Op\\Expr\\MagicScriptConst::KIND_LINE === $op->kind) {
                    return [Type::int()];
                }

                return [Type::string()];
""" + incdec_case + """        }

""" + throw_tail,
    ),
    (
        """            case 'Expr_FirstClassCallable':
                if (\\PHPCfg\\Op\\Expr\\FirstClassCallable::KIND_METHOD === $op->kind) {
                    return [new Type(Type::TYPE_ARRAY)];
                }

                return [Type::string()];
            case 'Expr_MagicScriptConst':
                if (\\PHPCfg\\Op\\Expr\\MagicScriptConst::KIND_LINE === $op->kind) {
                    return [Type::int()];
                }

                return [Type::string()];
        }

""" + throw_tail,
        """            case 'Expr_FirstClassCallable':
                if (\\PHPCfg\\Op\\Expr\\FirstClassCallable::KIND_METHOD === $op->kind) {
                    return [new Type(Type::TYPE_ARRAY)];
                }

                return [Type::string()];
            case 'Expr_MagicScriptConst':
                if (\\PHPCfg\\Op\\Expr\\MagicScriptConst::KIND_LINE === $op->kind) {
                    return [Type::int()];
                }

                return [Type::string()];
""" + incdec_case + """        }

""" + throw_tail,
    ),
    (
        """            case 'Expr_MagicScriptConst':
                if (\\PHPCfg\\Op\\Expr\\MagicScriptConst::KIND_LINE === $op->kind
                    || \\PHPCfg\\Op\\Expr\\MagicScriptConst::KIND_HALT_OFFSET === $op->kind) {
                    return [Type::int()];
                }

                return [Type::string()];
        }

""" + throw_tail,
        """            case 'Expr_MagicScriptConst':
                if (\\PHPCfg\\Op\\Expr\\MagicScriptConst::KIND_LINE === $op->kind
                    || \\PHPCfg\\Op\\Expr\\MagicScriptConst::KIND_HALT_OFFSET === $op->kind) {
                    return [Type::int()];
                }

                return [Type::string()];
""" + incdec_case + """        }

""" + throw_tail,
    ),
    (
        """            case 'Expr_MagicScriptConst':
                if (\\PHPCfg\\Op\\Expr\\MagicScriptConst::KIND_LINE === $op->kind) {
                    return [Type::int()];
                }

                return [Type::string()];
        }

""" + throw_tail,
        """            case 'Expr_MagicScriptConst':
                if (\\PHPCfg\\Op\\Expr\\MagicScriptConst::KIND_LINE === $op->kind) {
                    return [Type::int()];
                }

                return [Type::string()];
""" + incdec_case + """        }

""" + throw_tail,
    ),
    (
        """            case 'Expr_MagicScriptConst':
                if (\\PHPCfg\\Op\\Expr\\MagicScriptConst::KIND_LINE === $op->kind) {
                    return [Type::int()];
                }

                return [Type::string()];

        }

""" + throw_tail,
        """            case 'Expr_MagicScriptConst':
                if (\\PHPCfg\\Op\\Expr\\MagicScriptConst::KIND_LINE === $op->kind) {
                    return [Type::int()];
                }

                return [Type::string()];
""" + incdec_case + """        }

""" + throw_tail,
    ),
    (
        """            case 'Expr_FirstClassCallable':
                if (\\PHPCfg\\Op\\Expr\\FirstClassCallable::KIND_METHOD === $op->kind) {
                    return [new Type(Type::TYPE_ARRAY)];
                }

                return [Type::string()];
        }

""" + throw_tail,
        """            case 'Expr_FirstClassCallable':
                if (\\PHPCfg\\Op\\Expr\\FirstClassCallable::KIND_METHOD === $op->kind) {
                    return [new Type(Type::TYPE_ARRAY)];
                }

                return [Type::string()];
""" + incdec_case + """        }

""" + throw_tail,
    ),
]
for old, new in anchors:
    if old in text:
        path.write_text(text.replace(old, new, 1))
        raise SystemExit(0)
sys.stderr.write("php-types-incdec-type: TypeReconstructor switch marker not found\\n")
raise SystemExit(1)
PY
  then
    echo "ERROR: php-types-incdec-type overlay failed for ${target} (#6326, #6321)" >&2
    return 1
  fi
  echo "Applied php-types-incdec-type.patch (overlay): ${target}"
}

apply_php_types_incdec_type_overlay() {
  local rc=0
  if ! apply_php_types_incdec_type_overlay_to_target "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"; then
    rc=1
  fi
  if ! apply_php_types_incdec_type_overlay_to_target "$ROOT/prelinked/bootstrap-vendor/sources/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"; then
    rc=1
  fi
  if [[ "$rc" -ne 0 ]]; then
    record_patch_failure "php-types-incdec-type.patch" "PostInc TypeReconstructor arms missing — fix overlay anchors (#6321)"
  fi
  return "$rc"
}

apply_php_types_yield_from_overlay() {
  local target="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
  if grep -q "case 'Expr_YieldFrom':" "$target" 2>/dev/null; then
    echo "Skip php-types-yield-from.patch (already applied)"
    return 0
  fi
  python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
anchor = """            case 'Expr_Yield':
            case 'Expr_Include':"""
if anchor not in text:
    sys.stderr.write("php-types-yield-from: TypeReconstructor Expr_Yield anchor not found\n")
    raise SystemExit(1)
insert = """            case 'Expr_Yield':
            case 'Expr_YieldFrom':
            case 'Expr_Include':"""
path.write_text(text.replace(anchor, insert, 1))
PY
  echo "Applied php-types-yield-from.patch (overlay)"
}

apply_php_types_throw_expr_overlay_to_target() {
  local target="$1"
  if [[ ! -f "$target" ]]; then
    echo "Skip php-types-throw-expr.patch (target missing): ${target}"
    return 0
  fi
  if grep -q "case 'Expr_Throw':" "$target" 2>/dev/null; then
    if grep -A1 "case 'Expr_Throw':" "$target" 2>/dev/null | grep -q 'Type::never()'; then
      echo "Skip php-types-throw-expr.patch (already applied): ${target}"
      return 0
    fi
    # Upgrade legacy fall-through (Expr_Exit + Expr_Throw → null) to never (#6746).
    if ! python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
old = """            case 'Expr_Exit':
            case 'Expr_Throw':
            case 'Iterator_Reset':
                return [Type::null()];"""
new = """            case 'Expr_Exit':
            case 'Iterator_Reset':
                return [Type::null()];
            case 'Expr_Throw':
                return [Type::never()];"""
if old not in text:
    raise SystemExit(1)
path.write_text(text.replace(old, new, 1))
PY
    then
      echo "Skip php-types-throw-expr.patch (already applied): ${target}"
      return 0
    fi
    echo "Applied php-types-throw-expr.patch (never upgrade): ${target}"
    return 0
  fi
  if ! python3 - "$target" <<'PY'
import re
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
anchors = [
    (
        """            case 'Expr_Exit':
            case 'Iterator_Reset':
                return [Type::null()];""",
        """            case 'Expr_Exit':
            case 'Iterator_Reset':
                return [Type::null()];
            case 'Expr_Throw':
                return [Type::never()];""",
    ),
    (
        """            case 'Expr_Exit':
            case 'Expr_Throw':
            case 'Iterator_Reset':
                return [Type::null()];""",
        """            case 'Expr_Exit':
            case 'Iterator_Reset':
                return [Type::null()];
            case 'Expr_Throw':
                return [Type::never()];""",
    ),
    (
        """            case 'Expr_Exit':
                return [Type::null()];
            case 'Iterator_Reset':""",
        """            case 'Expr_Exit':
                return [Type::null()];
            case 'Expr_Throw':
                return [Type::never()];
            case 'Iterator_Reset':""",
    ),
]
for old, new in anchors:
    if old in text:
        path.write_text(text.replace(old, new, 1))
        raise SystemExit(0)

sys.stderr.write("php-types-throw-expr: TypeReconstructor Expr_Exit anchor not found\n")
raise SystemExit(1)
PY
  then
    echo "ERROR: php-types-throw-expr overlay failed for ${target} (#5151)" >&2
    return 1
  fi
  echo "Applied php-types-throw-expr.patch (overlay): ${target}"
}

apply_php_types_throw_expr_overlay() {
  local rc=0
  if ! apply_php_types_throw_expr_overlay_to_target "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"; then
    rc=1
  fi
  if ! apply_php_types_throw_expr_overlay_to_target "$ROOT/prelinked/bootstrap-vendor/sources/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"; then
    rc=1
  fi
  if [[ "$rc" -ne 0 ]]; then
    record_patch_failure "php-types-throw-expr.patch" "Expr_Throw TypeReconstructor arm missing — fix overlay anchors (#5151)"
  fi
  return "$rc"
}

apply_php_types_never_method_overlay_to_target() {
  local target="$1"
  if [[ ! -f "$target" ]]; then
    echo "Skip php-types-never-type.patch (target missing): ${target}"
    return 0
  fi
  if grep -q 'function never(): self' "$target" 2>/dev/null \
    && grep -q 'instanceof CfgType\\Never_' "$target" 2>/dev/null \
    && grep -q "case 'never':" "$target" 2>/dev/null; then
    echo "Skip php-types-never-type.patch (already applied): ${target}"
    return 0
  fi
  if ! python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
changed = False

if 'function never(): self' not in text:
    anchor = """    public static function null(): self
    {
        return self::makeCachedType(self::TYPE_NULL);
    }

    public static function object(): self"""
    insert = """    public static function null(): self
    {
        return self::makeCachedType(self::TYPE_NULL);
    }

    public static function never(): self
    {
        return self::makeCachedType(self::TYPE_NULL);
    }

    public static function object(): self"""
    if anchor not in text:
        raise SystemExit(1)
    text = text.replace(anchor, insert, 1)
    changed = True

if 'instanceof CfgType\\Never_' not in text:
    never_decl = """        if ($decl instanceof CfgType\\Never_) {
            return self::never();
        }
"""
    decl_anchors = [
        ("""        if ($decl instanceof CfgType\\Literal) {
            return self::fromDecl($decl->name);
        }
        if ($decl instanceof CfgType\\Mixed_) {""",
         """        if ($decl instanceof CfgType\\Literal) {
            return self::fromDecl($decl->name);
        }
""" + never_decl + """        if ($decl instanceof CfgType\\Mixed_) {"""),
        ("""        if ($decl instanceof CfgType\\Literal) {
            return self::fromDecl($decl->name);
        }

        throw new \\LogicException('Unsupported declaration type: '.get_class($decl));""",
         """        if ($decl instanceof CfgType\\Literal) {
            return self::fromDecl($decl->name);
        }
""" + never_decl + """
        throw new \\LogicException('Unsupported declaration type: '.get_class($decl));"""),
    ]
    for anchor, insert in decl_anchors:
        if anchor in text:
            text = text.replace(anchor, insert, 1)
            changed = True
            break
    else:
        raise SystemExit(2)

if "case 'never':" not in text:
    anchor = """            case 'null':
            case 'void':
                return new self(self::TYPE_NULL);
            case 'numeric':"""
    insert = """            case 'null':
            case 'void':
                return new self(self::TYPE_NULL);
            case 'never':
                return self::never();
            case 'numeric':"""
    if anchor not in text:
        raise SystemExit(3)
    text = text.replace(anchor, insert, 1)
    changed = True

if not changed:
    raise SystemExit(4)

path.write_text(text)
PY
  then
    echo "ERROR: php-types-never-type overlay failed for ${target} (#4137/#7329)" >&2
    return 1
  fi
  echo "Applied php-types-never-type.patch (never overlay): ${target}"
}

apply_php_types_never_type_reconstructor_overlay_to_target() {
  local target="$1"
  if [[ ! -f "$target" ]]; then
    echo "Skip php-types-never-type.patch TypeReconstructor (target missing): ${target}"
    return 0
  fi
  if grep -q 'instanceof Op\\Type\\Never_' "$target" 2>/dev/null; then
    return 0
  fi
  if ! python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
never_arm = """        } elseif ($type instanceof Op\\Type\\Never_) {
            return Type::never();
"""
anchors = [
    ("""        } elseif ($type instanceof Op\\Type\\Literal) {
            return Type::fromDecl($type->name);
        } elseif ($type instanceof Op\\Type\\Union_) {""",
     """        } elseif ($type instanceof Op\\Type\\Literal) {
            return Type::fromDecl($type->name);
""" + never_arm + """        } elseif ($type instanceof Op\\Type\\Union_) {"""),
    ("""        } elseif ($type instanceof Op\\Type\\Literal) {
            return Type::fromDecl($type->name);
        }

        throw new \\LogicException('Unknown Op\\\\Type provided: '.get_class($type));""",
     """        } elseif ($type instanceof Op\\Type\\Literal) {
            return Type::fromDecl($type->name);
""" + never_arm + """        }

        throw new \\LogicException('Unknown Op\\\\Type provided: '.get_class($type));"""),
]
for old, new in anchors:
    if old in text:
        path.write_text(text.replace(old, new, 1))
        raise SystemExit(0)
sys.stderr.write("php-types-never-type: TypeReconstructor Never_ anchor not found\n")
raise SystemExit(1)
PY
  then
    echo "ERROR: php-types-never-type TypeReconstructor overlay failed for ${target} (#4137/#7329)" >&2
    return 1
  fi
  echo "Applied php-types-never-type.patch (TypeReconstructor overlay): ${target}"
}

apply_php_types_never_type_overlay() {
  local rc=0
  if ! apply_php_types_never_method_overlay_to_target "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php"; then
    rc=1
  fi
  if ! apply_php_types_never_method_overlay_to_target "$ROOT/prelinked/bootstrap-vendor/sources/ircmaxell/php-types/lib/PHPTypes/Type.php"; then
    rc=1
  fi
  if ! apply_php_types_never_type_reconstructor_overlay_to_target "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"; then
    rc=1
  fi
  if ! apply_php_types_never_type_reconstructor_overlay_to_target "$ROOT/prelinked/bootstrap-vendor/sources/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"; then
    rc=1
  fi
  if [[ "$rc" -ne 0 ]]; then
    record_patch_failure "php-types-never-type.patch" "Type::never() missing — fix overlay anchors (#4137)"
  fi
  return "$rc"
}

apply_php_types_never_method_overlay() {
  apply_php_types_never_type_overlay
}

apply_php_types_hex2bin_strict_overlay() {
  local target="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php"
  if grep -q "'hex2bin' => \['string', 'data' => 'string', 'strict=' => 'bool'\]" "$target" 2>/dev/null; then
    echo "Skip php-types-hex2bin-strict.patch (already applied)"
    return 0
  fi
  python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
old = "        'hex2bin' => ['string', 'data' => 'string'],\n"
new = "        'hex2bin' => ['string', 'data' => 'string', 'strict=' => 'bool'],\n"
if old not in text:
    sys.stderr.write("php-types-hex2bin-strict: hex2bin anchor not found\n")
    raise SystemExit(1)
path.write_text(text.replace(old, new, 1))
PY
  echo "Applied php-types-hex2bin-strict.patch (overlay)"
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
                        // callable(T): R — space after ':' is return type, not trailing prose (#8559 spine).
                        if ($i > 0 && ':' === $decl[$i - 1]) {
                            break;
                        }
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

apply_php_types_callable_return_strip_overlay_to_target() {
  local target="$1"
  [[ -f "$target" ]] || return 0
  python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
needle = """                    if ($ch <= ' ' && 0 === $depthAngle && 0 === $depthParen && 0 === $depthSquare && 0 === $depthCurly) {
                        return trim(substr($decl, 0, $i));
                    }"""
replacement = """                    if ($ch <= ' ' && 0 === $depthAngle && 0 === $depthParen && 0 === $depthSquare && 0 === $depthCurly) {
                        // callable(T): R — space after ':' is return type, not trailing prose (#8559 spine).
                        if ($i > 0 && ':' === $decl[$i - 1]) {
                            break;
                        }
                        return trim(substr($decl, 0, $i));
                    }"""
if 'callable(T): R' not in text:
    if needle not in text:
        sys.stderr.write("php-types-callable-return-strip: stripTrailingDocText anchor not found\n")
        raise SystemExit(1)
    text = text.replace(needle, replacement, 1)

fromdecl_needle = "        // Docblock union splits may leave a lone \"string,\" fragment (M2 spine; #3012).\n"
fromdecl_insert = (
    "        // Docblock callable signatures: vendor only supports bare callable keyword (#8559 spine).\n"
    "        if (preg_match('/^callable\\s*\\(/i', $decl)) {\n"
    "            return new self(self::TYPE_CALLABLE);\n"
    "        }\n"
    + fromdecl_needle
)
fromdecl_alt_needle = "        switch (strtolower($decl)) {\n"
fromdecl_alt_insert = (
    "        // Docblock callable signatures: vendor only supports bare callable keyword (#8559 spine).\n"
    "        if (preg_match('/^callable\\s*\\(/i', $decl)) {\n"
    "            return new self(self::TYPE_CALLABLE);\n"
    "        }\n"
    + fromdecl_alt_needle
)
if 'callable signatures: vendor only supports bare callable' not in text:
    if fromdecl_needle in text:
        text = text.replace(fromdecl_needle, fromdecl_insert, 1)
    elif fromdecl_alt_needle in text:
        text = text.replace(fromdecl_alt_needle, fromdecl_alt_insert, 1)
    else:
        sys.stderr.write("php-types-callable-return-strip: fromDecl anchor not found\n")
        raise SystemExit(1)

path.write_text(text)
PY
}

apply_php_types_callable_return_strip_overlay() {
  if patch_already_applied "$PATCH_DIR/php-types-callable-return-strip.patch"; then
    echo "Skip php-types-callable-return-strip.patch (already applied)"
    return 0
  fi
  apply_php_types_callable_return_strip_overlay_to_target \
    "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php"
  apply_php_types_callable_return_strip_overlay_to_target \
    "$ROOT/prelinked/bootstrap-vendor/sources/ircmaxell/php-types/lib/PHPTypes/Type.php"
  echo "Applied php-types-callable-return-strip.patch (overlay)"
}

apply_php_types_docblock_full_type_overlay() {
  local target="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php"
  if patch_already_applied "$PATCH_DIR/php-types-docblock-first-token.patch"; then
    echo "Skip php-types-docblock-first-token.patch (already applied)"
    return 0
  fi
  python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
replacements = [
    (
        "                if (preg_match('(@var\\s+(\\S+))', $comment, $match)) {",
        "                if (preg_match('(@var\\s+(.+?)(?:\\s*\\*\\/|\\s*$))m', $comment, $match)) {",
    ),
    (
        "                if (preg_match('(@var\\s+([^\\s*][^\\s]*))', $comment, $match)) {",
        "                if (preg_match('(@var\\s+(.+?)(?:\\s*\\*\\/|\\s*$))m', $comment, $match)) {",
    ),
    (
        "                if (preg_match('(@return\\s+(\\S+))', $comment, $match)) {",
        "                if (preg_match('(@return\\s+(.+?)(?:\\s*\\*\\/|\\s*$))m', $comment, $match)) {",
    ),
    (
        "                if (preg_match('(@return\\s+([^\\s*][^\\s]*))', $comment, $match)) {",
        "                if (preg_match('(@return\\s+(.+?)(?:\\s*\\*\\/|\\s*$))m', $comment, $match)) {",
    ),
]
for old, new in replacements:
    if old in text:
        path.write_text(text.replace(old, new, 1))
        raise SystemExit(0)
sys.stderr.write("php-types-docblock-first-token: extractTypeFromComment anchor not found\n")
raise SystemExit(1)
PY
  echo "Applied php-types-docblock-first-token.patch (overlay)"
}

php_types_type_fromdecl_trailing_comma_corrupt() {
  local target="$1"
  [[ -f "$target" ]] || return 1
  grep -q 'Docblock union splits.*\\n        \$trimmedDecl' "$target" 2>/dev/null
}

apply_php_types_fromdecl_trailing_comma_overlay() {
  local target="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php"
  if patch_already_applied "$PATCH_DIR/php-types-fromdecl-trailing-comma.patch"; then
    echo "Skip php-types-fromdecl-trailing-comma.patch (already applied)"
    return 0
  fi
  if php_types_type_fromdecl_trailing_comma_corrupt "$target"; then
    echo "Repair php-types-fromdecl-trailing-comma.patch (literal \\\\n corruption; #9261)"
  fi
  python3 - "$target" <<'PY'
import re
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()

corrupt = re.compile(
    r"\n        // Docblock union splits may leave a lone[^\n]*\\n[^\n]*$",
    re.MULTILINE,
)
text, removed = corrupt.subn("\n", text, count=1)
if removed:
    path.write_text(text)

needle = "        $decl = self::stripTrailingDocText($decl);\n"
if needle not in text:
    sys.stderr.write("php-types-fromdecl-trailing-comma: stripTrailingDocText anchor not found\n")
    sys.exit(1)

insertion = needle + (
    "        // Docblock union splits may leave a lone \"string,\" fragment (M2 spine; #3012).\n"
    "        $trimmedDecl = trim($decl);\n"
    "        if (str_ends_with($trimmedDecl, ',') && !str_contains($trimmedDecl, '|') && !str_contains($trimmedDecl, '&')) {\n"
    "            return self::fromDecl(rtrim($trimmedDecl, ', '));\n"
    "        }\n"
)

if 'Docblock union splits may leave a lone' in text:
    if re.search(r"if \(str_ends_with\(\$trimmedDecl, ','\)", text):
        raise SystemExit(0)

path.write_text(text.replace(needle, insertion, 1))
PY
  echo "Applied php-types-fromdecl-trailing-comma.patch (overlay)"
}

apply_php_types_generic_null_tail_overlay() {
  local target="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php"
  if patch_already_applied "$PATCH_DIR/php-types-generic-null-tail.patch"; then
    echo "Skip php-types-generic-null-tail.patch (already applied)"
    return 0
  fi
  python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
needle = "        $decl = self::stripTrailingDocText($decl);\n"
insert = needle + (
    "        $trimmedDecl = trim($decl);\n"
    "        // list<T|null> union splits may pass a trailing \"null>\" fragment (#2276).\n"
    "        if (str_ends_with($trimmedDecl, '>') && !str_contains($trimmedDecl, '<')) {\n"
    "            $trimmedDecl = rtrim(substr($trimmedDecl, 0, -1));\n"
    "            $decl = $trimmedDecl;\n"
    "        }\n"
)
if needle not in text:
    sys.stderr.write("php-types-generic-null-tail: stripTrailingDocText line not found\n")
    raise SystemExit(1)
if 'list<T|null> union splits' in text:
    raise SystemExit(0)
path.write_text(text.replace(needle, insert, 1))
PY
  echo "Applied php-types-generic-null-tail.patch (overlay)"
}

apply_php_types_remove_type_empty_union_overlay_to_target() {
  local target="$1"
  if [[ ! -f "$target" ]]; then
    return 0
  fi
  if ! grep -q "throw new \\\\LogicException('Unknown type encountered')" "$target" 2>/dev/null; then
    return 0
  fi
  python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
old = "            throw new \\LogicException('Unknown type encountered');"
new = "            return self::mixed();"
if old not in text:
    raise SystemExit(0)
path.write_text(text.replace(old, new, 1))
PY
  echo "Applied php-types-remove-type-empty-union.patch (Type.php overlay): ${target}"
}

apply_php_types_remove_type_empty_union_overlay() {
  local rc=0
  local vendor="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php"
  local prelinked="$ROOT/prelinked/bootstrap-vendor/sources/ircmaxell/php-types/lib/PHPTypes/Type.php"
  local applied=0

  if [[ -f "$vendor" ]] && grep -q "throw new \\\\LogicException('Unknown type encountered')" "$vendor" 2>/dev/null; then
    apply_php_types_remove_type_empty_union_overlay_to_target "$vendor" || rc=1
    applied=1
  fi
  if [[ -f "$prelinked" ]] && grep -q "throw new \\\\LogicException('Unknown type encountered')" "$prelinked" 2>/dev/null; then
    apply_php_types_remove_type_empty_union_overlay_to_target "$prelinked" || rc=1
    applied=1
  fi
  if [[ "$applied" -eq 0 ]]; then
    echo "Skip php-types-remove-type-empty-union.patch (already applied)"
  else
    echo "Applied php-types-remove-type-empty-union.patch (overlay)"
  fi
  return "$rc"
}

apply_php_types_iterable_generic_overlay() {
  local target="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php"
  if grep -qE "preg_match\('/\^\(list\|array\|iterable\)" "$target" 2>/dev/null; then
    echo "Skip php-types-iterable-generic.patch (already applied)"
    return 0
  fi
  python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
old = "        if (preg_match('/^(list|array)\\s*</i', trim($decl))) {\n            return new self(self::TYPE_ARRAY);\n        }\n"
new = "        if (preg_match('/^(list|array|iterable)\\s*</i', trim($decl))) {\n            return new self(self::TYPE_ARRAY);\n        }\n"
if old in text:
    path.write_text(text.replace(old, new, 1))
    raise SystemExit(0)
if "preg_match('/^(list|array|iterable)" in text:
    raise SystemExit(0)
needle = "        if (preg_match('/^(positive|negative|non-zero)-int$/', $pseudo)) {\n            return new self(self::TYPE_LONG);\n        }\n"
insert = needle + new
if needle in text:
    path.write_text(text.replace(needle, insert, 1))
    raise SystemExit(0)
sys.stderr.write("php-types-iterable-generic: list|array generic anchor not found\n")
raise SystemExit(1)
PY
  echo "Applied php-types-iterable-generic.patch (overlay)"
}

apply_php_types_array_shape_overlay() {
  local target="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php"
  if patch_already_applied "$PATCH_DIR/php-types-array-shape.patch"; then
    echo "Skip php-types-array-shape.patch (already applied)"
    return 0
  fi
  python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
if "preg_match('/array\\{/i', $decl)" in text:
    raise SystemExit(0)
old = "        if (preg_match('/^array\\{/i', $decl)) {\n            return new self(self::TYPE_ARRAY);\n        }\n"
new = "        if (preg_match('/array\\{/i', $decl)) {\n            return new self(self::TYPE_ARRAY);\n        }\n"
if old in text:
    path.write_text(text.replace(old, new, 1))
    raise SystemExit(0)
needle = "        if (strpos($decl, '|') !== false || strpos($decl, '&') !== false || strpos($decl, '(') !== false) {\n"
insert = "        if (preg_match('/array\\{/i', $decl)) {\n            return new self(self::TYPE_ARRAY);\n        }\n" + needle
if needle in text:
    path.write_text(text.replace(needle, insert, 1))
    raise SystemExit(0)
sys.stderr.write("php-types-array-shape: anchor not found\n")
raise SystemExit(1)
PY
  echo "Applied php-types-array-shape.patch (overlay)"
}

apply_php_types_anonymous_class_type_overlay() {
  local target="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php"
  if grep -q "@anonymous\\\\x00" "$target" 2>/dev/null; then
    echo "Skip php-types-anonymous-class-type.patch (already applied)"
    return 0
  fi
  if grep -q 'AnonymousClass@' "$target" 2>/dev/null; then
    python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
old = "        if (preg_match('/^AnonymousClass@\\d+$/', trim($decl))) {\n            return new self(self::TYPE_OBJECT, [], $decl);\n        }\n"
new = "        if (preg_match('/@anonymous\\x00/', $decl)) {\n            return new self(self::TYPE_OBJECT, [], $decl);\n        }\n" + old
if old not in text:
    sys.stderr.write("php-types-anonymous-class-type: upgrade anchor not found\n")
    raise SystemExit(1)
path.write_text(text.replace(old, new, 1))
PY
    echo "Applied php-types-anonymous-class-type.patch (overlay upgrade)"
    return 0
  fi
  python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
needle = "        $regex = '(^([a-zA-Z_"
block = """        if (preg_match('/@anonymous\\x00/', $decl)) {
            return new self(self::TYPE_OBJECT, [], $decl);
        }
        if (preg_match('/^AnonymousClass@\\d+$/', trim($decl))) {
            return new self(self::TYPE_OBJECT, [], $decl);
        }
"""
idx = text.find(needle)
if idx < 0:
    sys.stderr.write("php-types-anonymous-class-type: Type.php anchor not found\n")
    raise SystemExit(1)
path.write_text(text[:idx] + block + text[idx:])
PY
  echo "Applied php-types-anonymous-class-type.patch (overlay)"
}

apply_php_types_ns_func_call_overlay() {
  apply_php_types_ns_func_call_overlay_to_target() {
    local target="$1"
    if grep -q 'function resolveOp_Expr_NsFuncCall' "$target" 2>/dev/null; then
      echo "Skip php-types-ns-func-call.patch (already applied): ${target}"
      return 0
    fi
    python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
ns_func_block = """    protected function resolveOp_Expr_NsFuncCall(Operand $var, Op\\Expr\\NsFuncCall $op, SplObjectStorage $resolved)
    {
        if ($op->nsName instanceof Operand\\Literal) {
            $name = strtolower($op->nsName->value);
            if (isset($this->state->functionLookup[$name])) {
                $result = [];
                foreach ($this->state->functionLookup[$name] as $func) {
                    if ($func->returnType) {
                        $result[] = Type::fromTypeDecl($func->returnType);
                    } else {
                        $result[] = Type::extractTypeFromComment('return', $func->getAttribute('doccomment'));
                    }
                }

                return $result;
            }
            if (isset($this->state->internalTypeInfo->functions[$name])) {
                $type = $this->state->internalTypeInfo->functions[$name];
                if (empty($type['return'])) {
                    return false;
                }

                return [Type::fromDecl($type['return'])];
            }
        }

        return false;
    }

"""
anchor = "    protected function resolveOp_Expr_New(Operand $var, Op\\Expr\\New_ $op, SplObjectStorage $resolved)"
if anchor not in text:
    sys.stderr.write("php-types-ns-func-call: resolveOp_Expr_New anchor not found\\n")
    raise SystemExit(1)
path.write_text(text.replace(anchor, ns_func_block + anchor, 1))
PY
    echo "Applied php-types-ns-func-call.patch (overlay): ${target}"
  }

  apply_php_types_ns_func_call_overlay_to_target "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
  apply_php_types_ns_func_call_overlay_to_target "$ROOT/prelinked/bootstrap-vendor/sources/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
}

apply_php_types_arrow_function_overlay() {
  apply_php_types_arrow_function_overlay_to_target() {
    local target="$1"
    if [[ ! -f "$target" ]]; then
      return 0
    fi
    if grep -q 'function resolveOp_Expr_ArrowFunction' "$target" 2>/dev/null; then
      echo "Skip php-types-arrow-function.patch (already applied): ${target}"
      return 0
    fi
    python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
arrow_block = """    protected function resolveOp_Expr_ArrowFunction(Operand $var, Op\\Expr\\ArrowFunction $op, SplObjectStorage $resolved)
    {
        return [new Type(Type::TYPE_OBJECT, [], 'Closure')];
    }

"""
anchor = "    protected function resolveOp_Expr_FuncCall(Operand $var, Op\\Expr\\FuncCall $op, SplObjectStorage $resolved)"
closure_anchor = "    protected function resolveOp_Expr_Closure(Operand $var, Op\\Expr\\Closure $op, SplObjectStorage $resolved)"
if closure_anchor in text and anchor in text:
    needle = """    protected function resolveOp_Expr_Closure(Operand $var, Op\\Expr\\Closure $op, SplObjectStorage $resolved)
    {
        return [new Type(Type::TYPE_OBJECT, [], 'Closure')];
    }

"""
    if needle in text:
        path.write_text(text.replace(needle, needle + arrow_block, 1))
        raise SystemExit(0)
if anchor not in text:
    sys.stderr.write("php-types-arrow-function: resolveOp_Expr_FuncCall anchor not found\\n")
    raise SystemExit(1)
path.write_text(text.replace(anchor, arrow_block + anchor, 1))
PY
    echo "Applied php-types-arrow-function.patch (overlay): ${target}"
  }

  apply_php_types_arrow_function_overlay_to_target "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
  apply_php_types_arrow_function_overlay_to_target "$ROOT/prelinked/bootstrap-vendor/sources/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
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

junk = (
    "        // Malformed phpdoc fragments in vendor trees (north-star5 prelink; #2743, #2745).\n"
    "        if ('' === $trimmedDecl || '*' === $trimmedDecl || '*/' === $trimmedDecl\n"
    "            || str_starts_with($trimmedDecl, '*/')) {\n"
    "            return self::mixed();\n"
    "        }\n"
)

if "Malformed phpdoc fragments in vendor trees" not in text:
    if "$trimmedDecl = trim($decl);" not in text:
        insert = needle + "        $trimmedDecl = trim($decl);\n" + junk
        text = text.replace(needle, insert, 1)
    else:
        anchor = "        $trimmedDecl = trim($decl);\n"
        if anchor in text:
            text = text.replace(anchor, anchor + junk, 1)
    path.write_text(text)
PY
  echo "Applied php-types-fromdecl-junk-fragments.patch (overlay)"
}

apply_php_cfg_in_operator_overlay() {
  local op="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/In_.php"
  local overlay="$PATCH_DIR/overlays/php-cfg/Op/Expr/In_.php"
  if [[ ! -f "$overlay" ]]; then
    echo "Skip php-cfg-in-operator overlay (missing $overlay)" >&2
    return 1
  fi
  mkdir -p "$(dirname "$op")"
  cp "$overlay" "$op"
  echo "Applied php-cfg-in-operator overlay (In_.php)"
}

apply_php_cfg_exit_two_arg_overlay() {
  local op="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Exit_.php"
  local overlay="$PATCH_DIR/overlays/php-cfg/Op/Expr/Exit_.php"
  if [[ ! -f "$overlay" ]]; then
    echo "Skip php-cfg-exit-two-arg overlay (missing $overlay)" >&2
    return 1
  fi
  mkdir -p "$(dirname "$op")"
  cp "$overlay" "$op"
  echo "Applied php-cfg-exit-two-arg overlay (Exit_.php)"
}

apply_php_cfg_void_cast_overlay() {
  local op="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Cast/Void_.php"
  local overlay="$PATCH_DIR/overlays/php-cfg/Op/Expr/Cast/Void_.php"
  if [[ ! -f "$overlay" ]]; then
    echo "Skip php-cfg-void-cast overlay (missing $overlay)" >&2
    return 1
  fi
  mkdir -p "$(dirname "$op")"
  cp "$overlay" "$op"
  echo "Applied php-cfg-void-cast overlay (Void_.php)"
}

apply_php_cfg_typed_class_const_overlay() {
  local const_op="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Terminal/Const_.php"
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local overlay="$PATCH_DIR/overlays/php-cfg/Op/Terminal/Const_.php"
  if patch_already_applied "$PATCH_DIR/php-cfg-typed-class-const.patch"; then
    echo "Skip php-cfg-typed-class-const.patch (already applied)"
    return 0
  fi
  if [[ ! -f "$overlay" ]]; then
    echo "Skip php-cfg-typed-class-const overlay (missing $overlay)" >&2
    return 1
  fi
  mkdir -p "$(dirname "$const_op")"
  cp "$overlay" "$const_op"
  python3 - "$parser" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
text = parser_path.read_text()
if 'declaredType = null !== $node->type' in text:
    sys.exit(0)
old = """            $this->block->children[] = new Op\\Terminal\\Const_(
                $this->parseExprNode($const->name),
                $value, $valueBlock,
                $this->mapAttributes($node)
            );"""
new = """            $constOp = new Op\\Terminal\\Const_(
                $this->parseExprNode($const->name),
                $value, $valueBlock,
                $this->mapAttributes($node)
            );
            $constOp->declaredType = null !== $node->type ? $this->parseTypeNode($node->type) : null;
            $constOp->flags = $node->flags;
            $this->block->children[] = $constOp;"""
if old not in text:
    raise SystemExit('php-cfg typed class const: parseStmt_ClassConst anchor missing')
parser_path.write_text(text.replace(old, new, 1))
PY
  echo "Applied php-cfg-typed-class-const overlay (#6012)"
}

apply_php_cfg_global_typed_const_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local overlay="$PATCH_DIR/overlays/php-cfg/global-typed-const-parser-methods.php"
  if [[ ! -f "$parser" || ! -f "$overlay" ]]; then
    return 0
  fi
  if grep -q 'function applyGlobalTypedConstMarkerAttributes' "$parser" 2>/dev/null; then
    python3 - "$parser" <<'PY'
import re
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
text = parser_path.read_text()
pattern = re.compile(
    r"/\*\*\n(?:     )?\* Parse a type expression embedded in a global typed-const marker via php-parser \(#7081\)\.\n(?:     )?\*/\n    private function parseGlobalTypedConstTypeFromMarker\(string \$typeExpr\): Op\\Type\n    \{.*?\n        return \$this->parseTypeNode\(\$class->stmts\[0\]->type\);\n    \}\n",
    re.S,
)
matches = list(pattern.finditer(text))
if len(matches) <= 1:
    raise SystemExit(0)
text = text[: matches[1].start()] + text[matches[1].end() :]
parser_path.write_text(text)
PY
    return 0
  fi
  python3 - "$parser" "$overlay" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
method_path = Path(sys.argv[2])
text = parser_path.read_text()
if 'function applyGlobalTypedConstMarkerAttributes' in text:
    raise SystemExit(0)

anchor = """    protected function parseExpr_Yield(Expr\\Yield_ $expr)
    {"""
if anchor not in text:
    sys.stderr.write("php-cfg-global-typed-const: parseExpr_Yield anchor not found in Parser.php\n")
    raise SystemExit(1)

insert = method_path.read_text().rstrip("\n") + "\n\n"
if 'function extractGlobalTypedConstDeclaredTypeFromAttributes' in text:
    old_methods = """    /**
     * Recover phpc-global-typed-const:* marker from comment attributes (#7081).
     */
    private function extractGlobalTypedConstDeclaredTypeFromAttributes(array $attributes): ?Op\\Type
    {
        $chunks = [];
        if (isset($attributes['comments']) && is_array($attributes['comments'])) {
            foreach ($attributes['comments'] as $comment) {
                if (is_object($comment) && method_exists($comment, 'getText')) {
                    $chunks[] = $comment->getText();
                } elseif (is_string($comment)) {
                    $chunks[] = $comment;
                }
            }
        }
        if (isset($attributes['docComment']) && is_object($attributes['docComment'])
            && method_exists($attributes['docComment'], 'getText')) {
            $chunks[] = $attributes['docComment']->getText();
        }
        foreach ($chunks as $chunk) {
            if (!preg_match(\\PHPCompiler\\Ast\\GlobalTypedConstRewriter::MARKER_PATTERN, $chunk, $m)) {
                continue;
            }
            $typeExpr = trim($m[1]);
            if ('' === $typeExpr) {
                continue;
            }

            return $this->parseGlobalTypedConstTypeFromMarker($typeExpr);
        }

        return null;
    }

    """
    if old_methods not in text:
        sys.stderr.write("php-cfg-global-typed-const: upgrade anchor missing (#9909)\n")
        raise SystemExit(1)
    text = text.replace(old_methods, insert, 1)
    text = text.replace(
        "$constOp->declaredType = $this->extractGlobalTypedConstDeclaredTypeFromAttributes($node->getAttributes());",
        "$this->applyGlobalTypedConstMarkerAttributes($constOp, $node->getAttributes());",
        1,
    )
    trailing_method = """    /**
     * Parse a type expression embedded in a global typed-const marker via php-parser (#7081).
     */
    private function parseGlobalTypedConstTypeFromMarker(string $typeExpr): Op\\Type
    {
        static $parser = null;
        if (null === $parser) {
            $parser = (new \\PhpParser\\ParserFactory())->create(\\PhpParser\\ParserFactory::PREFER_PHP7);
        }
        $probe = '<?php class __PhpcGlobalTypedConstProbe { public '.$typeExpr.' $p; }';
        try {
            $ast = $parser->parse($probe);
        } catch (\\PhpParser\\Error $e) {
            throw new \\RuntimeException('Invalid typed global constant type: '.$typeExpr, 0, $e);
        }
        if (!is_array($ast) || !isset($ast[0]) || !($ast[0] instanceof \\PhpParser\\Node\\Stmt\\Class_)) {
            throw new \\RuntimeException('Invalid typed global constant type probe: '.$typeExpr);
        }
        $class = $ast[0];
        if (!isset($class->stmts[0]) || !($class->stmts[0] instanceof \\PhpParser\\Node\\Stmt\\Property)) {
            throw new \\RuntimeException('Invalid typed global constant type probe property: '.$typeExpr);
        }

        return $this->parseTypeNode($class->stmts[0]->type);
    }

"""
    if text.count('function parseGlobalTypedConstTypeFromMarker') > 1 and trailing_method in text:
        text = text.replace(trailing_method, '', 1)
else:
    text = text.replace(anchor, insert + anchor, 1)
    old = """            $this->block->children[] = new Op\\Terminal\\Const_(
                $this->parseExprNode($const->namespacedName),
                $value, $valueBlock,
                $this->mapAttributes($node)
            );"""
    new = """            $constOp = new Op\\Terminal\\Const_(
                $this->parseExprNode($const->namespacedName),
                $value, $valueBlock,
                $this->mapAttributes($node)
            );
            $this->applyGlobalTypedConstMarkerAttributes($constOp, $node->getAttributes());
            $this->block->children[] = $constOp;"""
    if old not in text:
        sys.stderr.write("php-cfg-global-typed-const: parseStmt_Const anchor missing\n")
        raise SystemExit(1)
    text = text.replace(old, new, 1)

parser_path.write_text(text)
PY
  echo "Applied php-cfg-global-typed-const overlay (#7081, #9909)"
}

apply_php_cfg_typed_function_static_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local overlay="$PATCH_DIR/overlays/php-cfg/typed-function-static-parser-methods.php"
  if [[ ! -f "$parser" || ! -f "$overlay" ]]; then
    return 0
  fi
  if grep -q 'function applyTypedFunctionStaticMarkerAttributes' "$parser" 2>/dev/null; then
    return 0
  fi
  python3 - "$parser" "$overlay" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
method_path = Path(sys.argv[2])
text = parser_path.read_text()
if 'function applyTypedFunctionStaticMarkerAttributes' in text:
    raise SystemExit(0)

anchor = """    protected function parseStmt_Static(Stmt\\Static_ $node)
    {"""
if anchor not in text:
    sys.stderr.write("php-cfg-typed-function-static: parseStmt_Static anchor not found in Parser.php\n")
    raise SystemExit(1)

insert = method_path.read_text().rstrip("\n") + "\n\n"
text = text.replace(anchor, insert + anchor, 1)
old = """            $this->block->children[] = new Op\\Terminal\\StaticVar(
                $this->writeVariable(new Operand\\BoundVariable($this->parseExprNode($var->var->name), true, Operand\\BoundVariable::SCOPE_FUNCTION)),
                $defaultBlock,
                $defaultVar,
                $this->mapAttributes($node)
            );"""
new = """            $staticOp = new Op\\Terminal\\StaticVar(
                $this->writeVariable(new Operand\\BoundVariable($this->parseExprNode($var->var->name), true, Operand\\BoundVariable::SCOPE_FUNCTION)),
                $defaultBlock,
                $defaultVar,
                $this->mapAttributes($node)
            );
            $this->applyTypedFunctionStaticMarkerAttributes(
                $staticOp,
                array_merge($node->getAttributes(), $var->getAttributes())
            );
            $this->block->children[] = $staticOp;"""
if old not in text:
    sys.stderr.write("php-cfg-typed-function-static: parseStmt_Static body anchor missing\n")
    raise SystemExit(1)
text = text.replace(old, new, 1)
parser_path.write_text(text)
PY
  echo "Applied php-cfg-typed-function-static overlay (#9998)"
}

apply_php_cfg_list_spread_overlay() {
  local assign="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Assign.php"
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  if grep -q 'listSpreadExcludedKeys = \$excludedKeys' "$parser" 2>/dev/null; then
    echo "Skip php-cfg-list-spread.patch (already applied)"
    return 0
  fi
  python3 - "$assign" "$parser" <<'PY'
import sys
from pathlib import Path

assign_path = Path(sys.argv[1])
parser_path = Path(sys.argv[2])
assign = assign_path.read_text()
if 'listSpreadRhs' not in assign:
    needle = "    public $expr;\n\n    protected $writeVariables"
    insert = (
        "    public $expr;\n\n"
        "    /** `[$a, ...$rest] = $rhs` tail: full list RHS (#4835). */\n"
        "    public $listSpreadRhs = null;\n\n"
        "    /** Zero-based index of first element merged into the spread target (#4835). */\n"
        "    public $listSpreadFromIndex = null;\n\n"
        "    /** String literal keys consumed before spread (`['k' => $v, ...$tail]`, #4889). */\n"
        "    public $listSpreadExcludedKeys = [];\n\n"
        "    protected $writeVariables"
    )
    if needle not in assign:
        sys.stderr.write("php-cfg-list-spread: Assign.php anchor not found\n")
        raise SystemExit(1)
    assign_path.write_text(assign.replace(needle, insert, 1))

parser = parser_path.read_text()

old = """        $attributes = $this->mapAttributes($expr);
        foreach ($expr->items as $i => $item) {
            if (null === $item) {
                continue;
            }

            if ($item->key === null) {
                $key = new Operand\\Literal($i);
            } else {
                $key = $this->readVariable($this->parseExprNode($item->key));
            }

            $var = $item->value;
            $fetch = new Op\\Expr\\ArrayDimFetch($rhs, $key, $attributes);"""

new = """        $attributes = $this->mapAttributes($expr);
        $logicalIndex = 0;
        $excludedKeys = [];
        foreach ($expr->items as $i => $item) {
            if (null === $item) {
                continue;
            }

            if ($item->key === null) {
                $key = new Operand\\Literal($logicalIndex);
            } else {
                $key = $this->readVariable($this->parseExprNode($item->key));
            }

            $var = $item->value;
            if ($item->unpack) {
                $target = $this->writeVariable($this->parseExprNode($var));
                $assign = new Op\\Expr\\Assign($target, $rhs, $attributes);
                $assign->listSpreadRhs = $rhs;
                $assign->listSpreadFromIndex = $logicalIndex;
                $assign->listSpreadExcludedKeys = $excludedKeys;
                $this->block->children[] = $assign;

                continue;
            }

            if (null !== $item->key && $item->key instanceof Node\\Scalar\\String_) {
                $excludedKeys[] = $item->key->value;
            }

            if ($item->key === null) {
                ++$logicalIndex;
            }

            $fetch = new Op\\Expr\\ArrayDimFetch($rhs, $key, $attributes);"""

if old not in parser:
    sys.stderr.write("php-cfg-list-spread: Parser.php anchor not found\n")
    raise SystemExit(1)
parser_path.write_text(parser.replace(old, new, 1))
PY
  echo "Applied php-cfg-list-spread.patch (overlay)"
}

apply_php_cfg_empty_list_assignment_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  if patch_already_applied "$PATCH_DIR/php-cfg-empty-list-assignment.patch"; then
    echo "Skip php-cfg-empty-list-assignment.patch (already applied)"
    return 0
  fi
  python3 - "$parser" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
text = parser_path.read_text()
if 'isEmptyListExpr' in text:
    raise SystemExit(0)
old = """    /**
     * @param Expr\\List_|Expr\\Array_ $expr
     */
    protected function parseListAssignment($expr, Operand $rhs)
    {
        $attributes = $this->mapAttributes($expr);
        $logicalIndex = 0;"""
new = """    /**
     * @param Expr\\List_|Expr\\Array_ $expr
     */
    protected function isEmptyListExpr($expr): bool
    {
        foreach ($expr->items as $item) {
            if (null !== $item) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param Expr\\List_|Expr\\Array_ $expr
     */
    protected function parseListAssignment($expr, Operand $rhs)
    {
        if ($this->isEmptyListExpr($expr)) {
            throw new \\CompileError('Cannot use empty list');
        }

        $attributes = $this->mapAttributes($expr);
        $logicalIndex = 0;"""
if old not in text:
    sys.stderr.write("php-cfg-empty-list-assignment: Parser.php anchor not found\n")
    raise SystemExit(1)
parser_path.write_text(text.replace(old, new, 1))
PY
  echo "Applied php-cfg-empty-list-assignment.patch (overlay)"
}

apply_php_cfg_spread_overlay() {
  local operand="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Operand.php"
  local array_op="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Array_.php"
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  if patch_already_applied "$PATCH_DIR/php-cfg-spread.patch"; then
    echo "Skip php-cfg-spread.patch (already applied)"
    return 0
  fi
  python3 - "$operand" "$array_op" "$parser" <<'PY'
import sys
from pathlib import Path

operand_path = Path(sys.argv[1])
array_path = Path(sys.argv[2])
parser_path = Path(sys.argv[3])

operand = operand_path.read_text()
if 'callArgUnpack' not in operand:
    needle = "    public $callArgName = null;\n\n    public function getType()"
    insert = (
        "    public $callArgName = null;\n\n"
        "    /** Spread/unpack at call site (...$expr) (issue #141). */\n"
        "    public $callArgUnpack = false;\n\n"
        "    public function getType()"
    )
    if needle not in operand:
        sys.stderr.write("php-cfg-spread: Operand.php anchor not found\n")
        raise SystemExit(1)
    operand_path.write_text(operand.replace(needle, insert, 1))

array_text = array_path.read_text()
if 'public $unpack' not in array_text:
    old_ctor = "    public function __construct(array $keys, array $values, array $byRef, array $attributes = [])\n    {\n        parent::__construct($attributes);\n        $this->keys = $this->addReadRefs(...$keys);\n        $this->values = $this->addReadRefs(...$values);\n        $this->byRef = $byRef;\n    }"
    new_ctor = (
        "    /** @var list<bool> parallel to values; true when element is ...$expr (issue #141). */\n"
        "    public $unpack;\n\n"
        "    public function __construct(array $keys, array $values, array $byRef, array $unpack = [], array $attributes = [])\n"
        "    {\n        parent::__construct($attributes);\n        $this->keys = $this->addReadRefs(...$keys);\n        $this->values = $this->addReadRefs(...$values);\n        $this->byRef = $byRef;\n        $this->unpack = $unpack;\n    }"
    )
    if old_ctor not in array_text:
        sys.stderr.write("php-cfg-spread: Array_.php constructor anchor not found\n")
        raise SystemExit(1)
    array_path.write_text(array_text.replace(old_ctor, new_ctor, 1))

parser = parser_path.read_text()
if 'function parseCallArgs' not in parser:
    old_arg = """        if (null !== $expr->name) {
            $op->callArgName = $expr->name->toString();
        }

        return $op;
    }

    protected function parseExpr_Array(Expr\\Array_ $expr)"""
    new_arg = """        if (null !== $expr->name) {
            $op->callArgName = $expr->name->toString();
        }
        $op->callArgUnpack = $expr->unpack;

        return $op;
    }

    /**
     * @param list<Node\\Arg> $args
     *
     * @return Operand[]
     */
    protected function parseCallArgs(array $args): array
    {
        return array_map([$this, 'parseArg'], $args);
    }

    protected function parseExpr_Array(Expr\\Array_ $expr)"""
    if old_arg not in parser:
        sys.stderr.write("php-cfg-spread: Parser.php parseArg anchor not found\n")
        raise SystemExit(1)
    parser = parser.replace(old_arg, new_arg, 1)

if '$unpack[] = $item->unpack' not in parser:
    old_array = """        $keys = [];
        $values = [];
        $byRef = [];
        if ($expr->items) {
            foreach ($expr->items as $item) {
                if ($item->key) {
                    $keys[] = $this->readVariable($this->parseExprNode($item->key));
                } else {
                    $keys[] = new Operand\\NullOperand();
                }
                $values[] = $this->readVariable($this->parseExprNode($item->value));
                $byRef[] = $item->byRef;
            }
        }

        return new Op\\Expr\\Array_($keys, $values, $byRef, $this->mapAttributes($expr));"""
    new_array = """        $keys = [];
        $values = [];
        $byRef = [];
        $unpack = [];
        if ($expr->items) {
            foreach ($expr->items as $item) {
                if ($item->key) {
                    $keys[] = $this->readVariable($this->parseExprNode($item->key));
                } else {
                    $keys[] = new Operand\\NullOperand();
                }
                $values[] = $this->readVariable($this->parseExprNode($item->value));
                $byRef[] = $item->byRef;
                $unpack[] = $item->unpack;
            }
        }

        return new Op\\Expr\\Array_($keys, $values, $byRef, $unpack, $this->mapAttributes($expr));"""
    if old_array not in parser:
        sys.stderr.write("php-cfg-spread: Parser.php parseExpr_Array anchor not found\n")
        raise SystemExit(1)
    parser = parser.replace(old_array, new_array, 1)

parser = parser.replace(
    '$this->parseExprList($expr->args, self::MODE_READ)',
    '$this->parseCallArgs($expr->args)',
)
if 'parseCallArgs($expr->args)' not in parser:
    sys.stderr.write("php-cfg-spread: Parser.php call-site anchor not found\n")
    raise SystemExit(1)
parser_path.write_text(parser)
PY
  echo "Applied php-cfg-spread.patch (overlay)"
}

apply_php_cfg_call_arg_site_clone_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  if grep -q 'Per-call-site wrapper: unpack/named flags must not leak' "$parser" 2>/dev/null; then
    echo "Skip php-cfg-call-arg-site-clone.patch (already applied)"
    return 0
  fi
  python3 - "$parser" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
old = """        $op = $this->readVariable($this->parseExprNode($expr->value));
        if (null !== $expr->name) {
            $op->callArgName = $expr->name->toString();
        }
        $op->callArgUnpack = $expr->unpack;

        return $op;"""
new = """        $op = $this->readVariable($this->parseExprNode($expr->value));
        // Per-call-site wrapper: unpack/named flags must not leak across calls on shared Var operands (#8560).
        $site = clone $op;
        $site->callArgName = null;
        $site->callArgUnpack = false;
        if (null !== $expr->name) {
            $site->callArgName = $expr->name->toString();
        }
        $site->callArgUnpack = $expr->unpack;

        return $site;"""
if old not in text:
    sys.stderr.write("php-cfg-call-arg-site-clone: Parser.php parseArg anchor not found\n")
    raise SystemExit(1)
path.write_text(text.replace(old, new, 1))
PY
  echo "Applied php-cfg-call-arg-site-clone.patch (overlay)"
}

apply_php_cfg_simplifier_call_unpack_overlay() {
  local simplifier="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Visitor/Simplifier.php"
  if grep -q 'preserveCallSiteOperandMetadata' "$simplifier" 2>/dev/null; then
    echo "Skip php-cfg-simplifier-call-unpack.patch (already applied)"
    return 0
  fi
  python3 - "$simplifier" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
old = """                    if ($value === $from) {
                        $new[$key] = $to;
                        if ($op->isWriteVariable($name)) {
                            $to->addWriteOp($op);
                        } else {
                            $to->addUsage($op);
                        }
                    } else {
                        $new[$key] = $value;
                    }
                }
                $op->{$name} = $new;
            } elseif ($op->{$name} === $from) {
                $op->{$name} = $to;
                if ($op->isWriteVariable($name)) {
                    $to->addWriteOp($op);
                } else {
                    $to->addUsage($op);
                }
            }
        }
    }
}"""
new = """                    if ($value === $from) {
                        $new[$key] = $to;
                        $this->preserveCallSiteOperandMetadata($from, $to);
                        if ($op->isWriteVariable($name)) {
                            $to->addWriteOp($op);
                        } else {
                            $to->addUsage($op);
                        }
                    } else {
                        $new[$key] = $value;
                    }
                }
                $op->{$name} = $new;
            } elseif ($op->{$name} === $from) {
                $op->{$name} = $to;
                $this->preserveCallSiteOperandMetadata($from, $to);
                if ($op->isWriteVariable($name)) {
                    $to->addWriteOp($op);
                } else {
                    $to->addUsage($op);
                }
            }
        }
    }

    /** Keep named-call metadata when SSA simplifier replaces operands (#4321, #6838). */
    private function preserveCallSiteOperandMetadata(Operand $from, Operand $to): void
    {
        if (property_exists($from, 'callArgName') && null !== $from->callArgName) {
            $to->callArgName = $from->callArgName;
        }
    }
}"""
if old not in text:
    sys.stderr.write("php-cfg-simplifier-call-unpack: Simplifier.php anchor not found\n")
    raise SystemExit(1)
path.write_text(text.replace(old, new, 1))
PY
  echo "Applied php-cfg-simplifier-call-unpack.patch (overlay)"
}

apply_php_cfg_magic_script_const_overlay() {
  local op="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/MagicScriptConst.php"
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local overlay_op="$PATCH_DIR/overlays/php-cfg/Op/Expr/MagicScriptConst.php"
  if grep -q 'MagicScriptConst::KIND_LINE' "$parser" 2>/dev/null; then
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
applied = False
for old, new in replacements:
    if old in text:
        text = text.replace(old, new, 1)
        applied = True
if not applied:
    sys.stderr.write("php-cfg-magic-script-const: Parser.php anchor not found\n")
    raise SystemExit(1)
parser_path.write_text(text)
print("Applied php-cfg-magic-script-const.patch (overlay)")
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
    && grep -A3 'MagicConst\\Method' "$target" | grep -q 'functionStack' \
    && grep -q 'beginCompilationUnit' "$target" 2>/dev/null \
    && ! grep -q "return 'AnonymousClass@'" "$target" 2>/dev/null; then
    echo "Skip php-cfg-magic-constants.patch (already applied)"
    return 0
  fi
  cp "$overlay" "$target"
  echo "Applied php-cfg-magic-constants.patch (overlay)"
}

apply_php_cfg_anonymous_class_name_overlay() {
  local target="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  if [[ ! -f "$target" ]]; then
    return 0
  fi
  if grep -q 'magicStringResolver->beginCompilationUnit' "$target" 2>/dev/null; then
    echo "Skip php-cfg-anonymous-class-name.patch (already applied)"
    return 0
  fi
  python3 - "$target" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
text = parser_path.read_text()
original = text

if 'magicStringResolver->beginCompilationUnit' in text:
    print('Skip php-cfg-anonymous-class-name.patch (already applied)')
    raise SystemExit(0)

if 'protected $magicStringResolver' not in text:
    anchor = "    protected $astTraverser;\n\n    protected $fileName;"
    insert = (
        "    protected $astTraverser;\n\n"
        "    /** @var AstVisitor\\MagicStringResolver */\n"
        "    protected $magicStringResolver;\n\n"
        "    protected $fileName;"
    )
    if anchor in text:
        text = text.replace(anchor, insert, 1)
    else:
        anchor2 = "    protected $astTraverser;\n"
        if anchor2 not in text:
            sys.stderr.write('php-cfg-anonymous-class-name: astTraverser anchor not found\n')
            raise SystemExit(1)
        text = text.replace(
            anchor2,
            anchor2
            + "\n    /** @var AstVisitor\\MagicStringResolver */\n"
            + "    protected $magicStringResolver;\n",
            1,
        )

old_ctor = "        $this->astTraverser->addVisitor(new AstVisitor\\MagicStringResolver());"
new_ctor = (
    "        $this->magicStringResolver = new AstVisitor\\MagicStringResolver();\n"
    "        $this->astTraverser->addVisitor($this->magicStringResolver);"
)
if old_ctor in text:
    text = text.replace(old_ctor, new_ctor, 1)
elif '$this->magicStringResolver = new AstVisitor\\MagicStringResolver()' not in text:
    sys.stderr.write('php-cfg-anonymous-class-name: constructor anchor not found\n')
    raise SystemExit(1)

parse_ast_anchor = "        $this->fileName = $fileName;\n        $ast = $this->astTraverser->traverse($ast);"
parse_ast_insert = (
    "        $this->fileName = $fileName;\n"
    "        $this->magicStringResolver->beginCompilationUnit($fileName);\n"
    "        $ast = $this->astTraverser->traverse($ast);"
)
if parse_ast_anchor in text:
    text = text.replace(parse_ast_anchor, parse_ast_insert, 1)
else:
    sys.stderr.write('php-cfg-anonymous-class-name: parseAst anchor not found\n')
    raise SystemExit(1)

if text != original:
    parser_path.write_text(text)
    print('Applied php-cfg-anonymous-class-name.patch (overlay)')
raise SystemExit(0)
PY
}

apply_php_cfg_halt_compiler_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local op="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/HaltCompiler.php"
  local overlay="$PATCH_DIR/overlays/php-cfg"
  if grep -q 'new Op\\Stmt\\HaltCompiler' "$parser" 2>/dev/null; then
    echo "Skip php-cfg-halt-compiler.patch (already applied)"
    return 0
  fi
  if [[ ! -f "$overlay/Op/Stmt/HaltCompiler.php" || ! -f "$overlay/halt-compiler-parser-method.php" ]]; then
    echo "Skip php-cfg-halt-compiler.patch (overlay files missing)" >&2
    return 1
  fi
  mkdir -p "$(dirname "$op")"
  cp "$overlay/Op/Stmt/HaltCompiler.php" "$op"
  python3 - "$parser" "$overlay/halt-compiler-parser-method.php" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
method_path = Path(sys.argv[2])
text = parser_path.read_text()
old = """    protected function parseStmt_HaltCompiler(Stmt\\HaltCompiler $node)
    {
        $this->block->children[] = new Op\\Terminal\\Echo_(
            $this->readVariable(new Operand\\Literal($node->remaining)),
            $this->mapAttributes($node)
        );
    }"""
new = method_path.read_text()
if old not in text:
    sys.stderr.write("php-cfg-halt-compiler: parseStmt_HaltCompiler stub not found in Parser.php\n")
    sys.exit(1)
parser_path.write_text(text.replace(old, new.rstrip("\n"), 1))
PY
  echo "Applied php-cfg-halt-compiler.patch (overlay)"
}

# __COMPILER_HALT_OFFSET__ magic constant + halt byte offset on Stmt\HaltCompiler (#5455).
apply_php_cfg_compiler_halt_offset_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local msc="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/MagicScriptConst.php"
  local halt="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/HaltCompiler.php"
  local overlay="$PATCH_DIR/overlays/php-cfg"
  if grep -q 'KIND_HALT_OFFSET' "$msc" 2>/dev/null \
    && grep -q 'parseSourceCode' "$parser" 2>/dev/null \
    && grep -q 'haltOffset' "$halt" 2>/dev/null; then
    echo "Skip php-cfg-compiler-halt-offset overlay (already applied)"
    return 0
  fi
  if [[ ! -f "$overlay/Op/Expr/MagicScriptConst.php" \
    || ! -f "$overlay/Op/Stmt/HaltCompiler.php" \
    || ! -f "$overlay/halt-compiler-parser-method.php" \
    || ! -f "$overlay/parser-compiler-halt-offset.php" ]]; then
    echo "Skip php-cfg-compiler-halt-offset overlay (files missing)" >&2
    return 1
  fi
  cp "$overlay/Op/Expr/MagicScriptConst.php" "$msc"
  cp "$overlay/Op/Stmt/HaltCompiler.php" "$halt"
  python3 - "$parser" "$overlay/halt-compiler-parser-method.php" "$overlay/parser-compiler-halt-offset.php" <<'PY'
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
method_path = Path(sys.argv[2])
property_path = Path(sys.argv[3])
text = parser_path.read_text()
prop = property_path.read_text().rstrip("\n")
if "parseSourceCode" not in text:
    anchor = "    protected $anonId = 0;\n"
    if anchor not in text:
        sys.stderr.write("php-cfg-compiler-halt-offset: Parser anonId anchor not found\n")
        raise SystemExit(1)
    text = text.replace(anchor, anchor + "\n" + prop + "\n", 1)
    old_parse = """    public function parse($code, $fileName)
    {
        return $this->parseAst($this->astParser->parse($code), $fileName);
    }"""
    new_parse = """    public function parse($code, $fileName)
    {
        $this->parseSourceCode = $code;

        return $this->parseAst($this->astParser->parse($code), $fileName);
    }"""
    if old_parse not in text:
        sys.stderr.write("php-cfg-compiler-halt-offset: Parser::parse anchor not found\n")
        raise SystemExit(1)
    text = text.replace(old_parse, new_parse, 1)
old_halt = """    protected function parseStmt_HaltCompiler(Stmt\\HaltCompiler $node)
    {
        $attrs = $this->mapAttributes($node);
        $this->block->children[] = new Op\\Stmt\\HaltCompiler(
            $node->remaining,
            $attrs
        );
        $this->block = new Block();
        $this->block->dead = true;
    }"""
new_halt = method_path.read_text().rstrip("\n")
if old_halt in text:
    text = text.replace(old_halt, new_halt, 1)
elif new_halt not in text:
    sys.stderr.write("php-cfg-compiler-halt-offset: parseStmt_HaltCompiler anchor not found\n")
    raise SystemExit(1)
const_anchor = """    protected function parseExpr_ConstFetch(Expr\\ConstFetch $expr)
    {
        if ($expr->name->isUnqualified()) {"""
const_new = """    protected function parseExpr_ConstFetch(Expr\\ConstFetch $expr)
    {
        $lcConstName = strtolower(ltrim($expr->name->toString(), '\\\\'));
        if ('__compiler_halt_offset__' === $lcConstName) {
            $op = new Op\\Expr\\MagicScriptConst(
                Op\\Expr\\MagicScriptConst::KIND_HALT_OFFSET,
                $this->mapAttributes($expr)
            );
            $this->block->children[] = $op;

            return $op->result;
        }

        if ($expr->name->isUnqualified()) {"""
if const_anchor not in text:
    sys.stderr.write("php-cfg-compiler-halt-offset: parseExpr_ConstFetch anchor not found\n")
    raise SystemExit(1)
text = text.replace(const_anchor, const_new, 1)
# Upgrade path when the in-switch case was applied on a prior run.
text = text.replace(
    """                case '__compiler_halt_offset__':
                    $op = new Op\\Expr\\MagicScriptConst(
                        Op\\Expr\\MagicScriptConst::KIND_HALT_OFFSET,
                        $this->mapAttributes($expr)
                    );
                    $this->block->children[] = $op;

                    return $op->result;
""",
    "",
    1,
)
parser_path.write_text(text)
PY
  echo "Applied php-cfg-compiler-halt-offset overlay (#5455)"
}

apply_php_types_compiler_halt_offset_overlay() {
  local vendor_target="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
  local prelinked_target="$ROOT/prelinked/bootstrap-vendor/sources/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
  local -a targets=("$vendor_target")
  if [[ -f "$prelinked_target" ]]; then
    targets+=("$prelinked_target")
  fi

  python3 - "${targets[@]}" <<'PY'
import sys
import re
from pathlib import Path

def patch_one(path: Path) -> bool:
    text = path.read_text()

    if "KIND_HALT_OFFSET" in text:
        return False
    if "MagicScriptConst::KIND_LINE" not in text:
        return False

    # Try an exact-string replacement first (fast path).
    old = """                if (\\PHPCfg\\Op\\Expr\\MagicScriptConst::KIND_LINE === $op->kind) {
                    return [Type::int()];
                }"""
    new = """                if (\\PHPCfg\\Op\\Expr\\MagicScriptConst::KIND_LINE === $op->kind
                    || \\PHPCfg\\Op\\Expr\\MagicScriptConst::KIND_HALT_OFFSET === $op->kind) {
                    return [Type::int()];
                }"""
    if old in text:
        path.write_text(text.replace(old, new, 1))
        return True

    # Anchor drift: match the KIND_LINE guard even if formatting/spacing differs.
    pattern = re.compile(
        r"(?P<indent>[ \t]+)if\s*\(\s*\\PHPCfg\\Op\\Expr\\MagicScriptConst::KIND_LINE\s*===\s*\\$op->kind\s*\)\s*\{\s*\n"
        r"(?P=indent)[ \t]+return\s*\\[Type::int\\(\\)\\];\s*\n"
        r"(?P=indent)\\}",
        re.MULTILINE,
    )
    m = pattern.search(text)
    if not m:
        raise RuntimeError("php-types-compiler-halt-offset: TypeReconstructor anchor not found")

    indent = m.group("indent")
    replacement = (
        f"{indent}if (\\PHPCfg\\Op\\Expr\\MagicScriptConst::KIND_LINE === $op->kind\n"
        f"{indent}    || \\PHPCfg\\Op\\Expr\\MagicScriptConst::KIND_HALT_OFFSET === $op->kind) {{\n"
        f"{indent}    return [Type::int()];\n"
        f"{indent}}}"
    )
    path.write_text(text[: m.start()] + replacement + text[m.end() :])
    return True


modified_any = False
for arg in sys.argv[1:]:
    p = Path(arg)
    if not p.exists():
        continue
    try:
        modified_any = patch_one(p) or modified_any
    except RuntimeError as e:
        sys.stderr.write(str(e) + "\n")
        raise SystemExit(1)

if modified_any:
    sys.stderr.write("php-types-compiler-halt-offset: patched TypeReconstructor\n")
PY

  if grep -q 'KIND_HALT_OFFSET' "$vendor_target" 2>/dev/null; then
    echo "Applied php-types-compiler-halt-offset overlay (#5455)"
  else
    echo "Skip php-types-compiler-halt-offset overlay (already applied)"
  fi
}

# Per-property MODIFIER_READONLY: Property.propertyFlags + Parser assignment (#3149, #4230).
apply_php_cfg_readonly_function_overlay() {
  local func="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Func.php"
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  if [[ ! -f "$func" || ! -f "$parser" ]]; then
    echo "Skip php-cfg-readonly-function.patch (vendor php-cfg missing)" >&2
    return 1
  fi
  if patch_already_applied "$PATCH_DIR/php-cfg-readonly-function.patch"; then
    echo "Skip php-cfg-readonly-function.patch (already applied)"
    return 0
  fi
  python3 - "$func" "$parser" <<'PY'
import sys
from pathlib import Path

func_path = Path(sys.argv[1])
parser_path = Path(sys.argv[2])

func_text = func_path.read_text()
if "const FLAG_READONLY = 0x100;" not in func_text:
    needle = "    const FLAG_CLOSURE = 0x80;\n"
    insert = needle + "\n    const FLAG_READONLY = 0x100;\n"
    if needle not in func_text:
        sys.stderr.write("php-cfg-readonly-function: Func.php FLAG_CLOSURE anchor missing\n")
        raise SystemExit(1)
    func_text = func_text.replace(needle, insert, 1)
    func_path.write_text(func_text)

parser_text = parser_path.read_text()

stmt_old = """        $this->script->functions[] = $func = new Func(
            $node->namespacedName->toString(),
            $node->byRef ? Func::FLAG_RETURNS_REF : 0,"""
stmt_new = """        $this->script->functions[] = $func = new Func(
            $node->namespacedName->toString(),
            ($node->byRef ? Func::FLAG_RETURNS_REF : 0)
                | ($node->getAttribute('compilerReadonlyFunction', false) ? Func::FLAG_READONLY : 0),"""
if stmt_new not in parser_text:
    if stmt_old not in parser_text:
        sys.stderr.write("php-cfg-readonly-function: parseStmt_Function anchor missing\n")
        raise SystemExit(1)
    parser_text = parser_text.replace(stmt_old, stmt_new, 1)

readonly_block = """        if ($expr->getAttribute('compilerReadonlyFunction', false)) {
            $flags |= Func::FLAG_READONLY;
        }
"""

def inject_closure_readonly(text: str, method: str) -> str:
    marker = f"protected function {method}"
    start = text.find(marker)
    if start == -1:
        return text
    rest = text[start:]
    next_fn = rest.find("\n    protected function ", len(marker))
    method_body = rest if next_fn == -1 else rest[:next_fn]
    if "compilerReadonlyFunction" in method_body:
        return text
    anchor = "        $flags |= $expr->static ? Func::FLAG_STATIC : 0;\n"
    if anchor not in method_body:
        sys.stderr.write(f"php-cfg-readonly-function: {method} static flags anchor missing\n")
        raise SystemExit(1)
    new_method_body = method_body.replace(anchor, anchor + "\n" + readonly_block, 1)
    return text[:start] + new_method_body + text[start + len(method_body):]

parser_text = inject_closure_readonly(parser_text, "parseExpr_Closure")
if "function parseExpr_ArrowFunction" in parser_text:
    parser_text = inject_closure_readonly(parser_text, "parseExpr_ArrowFunction")

if "const FLAG_READONLY = 0x100;" not in func_path.read_text():
    sys.stderr.write("php-cfg-readonly-function: Func.php FLAG_READONLY missing after overlay\n")
    raise SystemExit(1)
closure_slice = parser_text.split("parseExpr_Closure", 1)[1].split("protected function", 1)[0]
if "compilerReadonlyFunction" not in closure_slice:
    sys.stderr.write("php-cfg-readonly-function: parseExpr_Closure readonly wiring missing\n")
    raise SystemExit(1)
if "function parseExpr_ArrowFunction" in parser_text:
    arrow_slice = parser_text.split("parseExpr_ArrowFunction", 1)[1].split("protected function", 1)[0]
    if "compilerReadonlyFunction" not in arrow_slice:
        sys.stderr.write("php-cfg-readonly-function: parseExpr_ArrowFunction readonly wiring missing\n")
        raise SystemExit(1)

parser_path.write_text(parser_text)
PY
  echo "Applied php-cfg-readonly-function.patch (overlay)"
  return 0
}

apply_php_cfg_property_readonly_overlay() {
  local prop="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/Property.php"
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  if [[ ! -f "$prop" || ! -f "$parser" ]]; then
    return 0
  fi
  if grep -qE 'propertyFlags = \$node->flags|\$cfgProp->readonly =|\$prop->readonly =|\$property->readonly =|->readonly = 0 !== \\(\\$node->flags & .*MODIFIER_READONLY\\)' "$parser" 2>/dev/null; then
    echo "Skip php-cfg-property-readonly.patch (already applied)"
    return 0
  fi
  if ! grep -q 'propertyFlags' "$prop" 2>/dev/null && ! grep -q 'public $readonly' "$prop" 2>/dev/null; then
    python3 - "$prop" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
if 'propertyFlags' in text or 'public $readonly' in text:
    raise SystemExit(0)
needle = "    public $declaredType;\n"
insert = (
    needle
    + "\n"
    + "    /** php-parser Stmt\\Property flags (includes MODIFIER_READONLY, issue #3149). */\n"
    + "    public int $propertyFlags = 0;\n"
)
if needle not in text:
    sys.stderr.write("php-cfg-property-readonly: Property.php declaredType anchor missing\n")
    raise SystemExit(1)
path.write_text(text.replace(needle, insert, 1))
PY
    echo "Applied php-cfg-property-readonly.patch (Property overlay)"
  fi
  python3 - "$parser" <<'PY'
import re
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()
if re.search(r'propertyFlags\s*=\s*\$node->flags', text):
    raise SystemExit(0)
if re.search(r'\$(cfgProp|prop)->readonly\s*=', text):
    raise SystemExit(0)
assign = "            $prop->propertyFlags = $node->flags;\n"
needle = "            $this->block->children[] = $prop;\n"
if needle in text and assign not in text:
    path.write_text(text.replace(needle, assign + needle, 1))
    raise SystemExit(0)
inline = re.compile(
    r"(\s+)\$this->block->children\[\] = new Op\\Stmt\\Property\(\n"
    r"([\s\S]*?)\n\1\);\n",
    re.M,
)
match = inline.search(text)
if match:
    indent = match.group(1)
    inner = match.group(2)
    replacement = (
        f"{indent}$prop = new Op\\Stmt\\Property(\n"
        f"{inner}\n"
        f"{indent});\n"
        f"{assign}"
        f"{indent}$this->block->children[] = $prop;\n"
    )
    path.write_text(text[: match.start()] + replacement + text[match.end() :])
    raise SystemExit(0)
sys.stderr.write("php-cfg-property-readonly: parseStmt_Property insert anchor missing\n")
raise SystemExit(1)
PY
  echo "Applied php-cfg-property-readonly.patch (Parser overlay)"
  return 0
}

apply_php_cfg_throw_expr_overlay() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local op="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Throw_.php"
  local overlay="$PATCH_DIR/overlays/php-cfg"
  if grep -q 'return new Op\\Expr\\Throw_' "$parser" 2>/dev/null; then
    echo "Skip php-cfg-throw-expr.patch (already applied)"
    return 0
  fi
  if [[ ! -f "$overlay/Op/Expr/Throw_.php" || ! -f "$overlay/throw-expr-parser-method.php" ]]; then
    echo "Skip php-cfg-throw-expr.patch (overlay files missing)" >&2
    return 1
  fi
  mkdir -p "$(dirname "$op")"
  cp "$overlay/Op/Expr/Throw_.php" "$op"
  python3 - "$parser" "$overlay/throw-expr-parser-method.php" <<'PY'
import re
import sys
from pathlib import Path

parser_path = Path(sys.argv[1])
method_path = Path(sys.argv[2])
text = parser_path.read_text()
new = method_path.read_text().rstrip("\n")
terminal = re.compile(
    r"    protected function parseExpr_Throw\(Expr\\Throw_ \$expr\)\s*\{"
    r".*?\n    \}\n",
    re.S,
)
if "return new Op\\Expr\\Throw_" in text:
    sys.exit(0)
match = terminal.search(text)
if match:
    parser_path.write_text(text[: match.start()] + new + "\n" + text[match.end() :])
else:
    anchor = "    protected function parseStmt_Trait(Stmt\\Trait_ $node)"
    if anchor not in text:
        sys.stderr.write("php-cfg-throw-expr: insert anchor not found in Parser.php\n")
        sys.exit(1)
    parser_path.write_text(text.replace(anchor, new + "\n" + anchor, 1))
PY
  echo "Applied php-cfg-throw-expr.patch (overlay)"
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

ARRAY_MAP_IN_MAKE_ARRAY = """            array_map(
                function(Type $type) {
                    return $type->type;
                },
                {iterable}
            )"""

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


def replace_once(text: str, old: str, new: str, label: str) -> str:
    if old not in text:
        return text
    return text.replace(old, new, 1)


def replace_array_map_in_make_array(text: str, iterable: str, types_var: str) -> str:
    old = ARRAY_MAP_IN_MAKE_ARRAY.format(iterable=iterable)
    if old not in text:
        old = old.replace(",\n                ", ", \n                ")
    if old not in text:
        return text
    new = f"""            ${types_var} = [];
            foreach ({iterable} as $type) {{
                ${types_var}[] = $type->type;
            }}"""
    return text.replace(old, new, 1)


def replace_function_type(context: str) -> str:
    if "foreach ($parameters as $type)" in context:
        return context
    anchors = [
        """    public function functionType(CoreType $returnType, bool $isVarArgs, CoreType ... $parameters): CoreFunctionType {
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
    }""",
        """    public function functionType(CoreType $returnType, bool $isVarArgs, CoreType ... $parameters): CoreFunctionType {
        $paramWrapper = null;
        if (count($parameters) > 0) {
            $paramWrapper = $this->llvm->lib->makeArray(
                LLVMTypeRef_ptr::class,
                array_map(
                    function(Type $type) {
                        return $type->type;
                    },
                    $parameters
                )
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
    }""",
    ]
    for old in anchors:
        if old in context:
            return replace_once(context, old, new_function_type, "functionType")
    updated = replace_array_map_in_make_array(context, "$parameters", "paramTypes")
    if updated != context:
        return updated
    sys.stderr.write("php-llvm-no-closures-array-map: expected Context.php functionType anchor not found\n")
    sys.exit(1)


def replace_struct_type(context: str) -> str:
    if "foreach ($elements as $type)" in context and "public function structType" in context:
        before = context.split("public function structType", 1)[1]
        if "array_map(" not in before.split("public function", 1)[0]:
            return context
    anchors = [
        """    public function structType(bool $packed, CoreType ... $elements): CoreType {
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
    }""",
        """    public function structType(bool $packed, CoreType ... $elements): CoreType {
        $elementWrapper = null;
        if (count($elements) > 0) {
            $elementWrapper = $this->llvm->lib->makeArray(
                LLVMTypeRef_ptr::class,
                array_map(
                    function(Type $type) {
                        return $type->type;
                    },
                    $elements
                )
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
    }""",
    ]
    for old in anchors:
        if old in context:
            return replace_once(context, old, new_struct_type, "structType")
    updated = replace_array_map_in_make_array(context, "$elements", "elementTypes")
    if updated != context:
        return updated
    sys.stderr.write("php-llvm-no-closures-array-map: expected Context.php structType anchor not found\n")
    sys.exit(1)


context = context_path.read_text()
context = replace_function_type(context)
context = replace_struct_type(context)
context_path.write_text(context)

struct = struct_path.read_text()
if "foreach ($elements as $type)" not in struct:
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
    if old_set_body not in struct:
        updated = replace_array_map_in_make_array(struct, "$elements", "elementTypes")
        if updated == struct:
            sys.stderr.write("php-llvm-no-closures-array-map: expected Struct.php anchor not found\n")
            sys.exit(1)
        struct = updated
    else:
        struct = struct.replace(old_set_body, new_set_body, 1)
struct_path.write_text(struct)
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

repair_php_llvm_token_type_kind_typo_in_prelinked() {
  local target="$ROOT/prelinked/bootstrap-vendor/sources/ircmaxell/php-llvm/lib/LLVMAbstract/Type.php"
  if [[ ! -f "$target" ]]; then
    return 0
  fi
  if grep -q 'LLVMTokenTypeKin' "$target" 2>/dev/null; then
    sed -i 's/lib::LLVMTokenTypeKin:/lib::LLVMTokenTypeKind:/' "$target"
    echo "Repaired php-llvm LLVMTokenTypeKin typo in prelinked bootstrap-vendor (#11396)"
  fi
}

# Apply a patch file with git/patch(1) only — no overlay dispatch (avoids recursion).
apply_patch_file_direct() {
  local patch="$1"
  local patch_name
  patch_name="$(basename "$patch")"
  if [[ ! -f "$patch" ]]; then
    return 0
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
  if git -C "$ROOT" apply --check -p1 "$patch" >/dev/null 2>&1; then
    git -C "$ROOT" apply -p1 "$patch"
    echo "Applied ${patch_name} (-p1)"
    return 0
  fi
  if command -v patch >/dev/null 2>&1; then
    if patch -p0 --dry-run -s -f < "$patch" >/dev/null 2>&1; then
      patch -p0 -s -f < "$patch" >/dev/null 2>&1
      echo "Applied ${patch_name} (patch(1))"
      return 0
    fi
    if patch -p1 --dry-run -s -f < "$patch" >/dev/null 2>&1; then
      patch -p1 -s -f < "$patch" >/dev/null 2>&1
      echo "Applied ${patch_name} (patch(1), -p1)"
      return 0
    fi
    if patch -p0 --reverse --dry-run -s -f < "$patch" >/dev/null 2>&1; then
      echo "Skip ${patch_name} (already applied)"
      return 0
    fi
    if patch -p1 --reverse --dry-run -s -f < "$patch" >/dev/null 2>&1; then
      echo "Skip ${patch_name} (already applied, -p1)"
      return 0
    fi
  fi
  if patch_marker_present "$patch"; then
    echo "Skip ${patch_name} (already applied)"
    return 0
  fi
  record_patch_failure "${patch_name}"
  return 1
}

apply_patch() {
  local patch="$1"
  local patch_name
  patch_name="$(basename "$patch")"
  if [[ ! -f "$patch" ]]; then
    return 0
  fi
  if [[ "$patch_name" == "php-cfg-new-ctor-parens.patch" ]]; then
    # Optional, known-stale diff (#6549). Keep it non-fatal (do not record failure).
    echo "Skip ${patch_name} (optional — stale hunk #6549; does not block throw-expr #6746)"
    return 0
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
  if [[ "$(basename "$patch")" == "php-cfg-enum-abstract.patch" ]]; then
    apply_php_cfg_enum_abstract_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-enum-class-const.patch" ]]; then
    apply_php_cfg_enum_class_const_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-enum-trait-use.patch" ]]; then
    apply_php_cfg_enum_trait_use_parser_fix
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
  if [[ "$(basename "$patch")" == "php-types-docblock-first-token.patch" ]]; then
    apply_php_types_docblock_full_type_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-iterable-generic.patch" ]]; then
    apply_php_types_iterable_generic_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-array-shape.patch" ]]; then
    apply_php_types_array_shape_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-generics-fallback.patch" ]]; then
    local target="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php"
    if grep -q "non-empty-string" "$target" 2>/dev/null; then
      echo "Skip php-types-generics-fallback.patch (already applied)"
      return 0
    fi
    if patch -p0 --forward --dry-run < "$PATCH_DIR/php-types-generics-fallback.patch" >/dev/null 2>&1; then
      patch -p0 --forward < "$PATCH_DIR/php-types-generics-fallback.patch"
      echo "Applied php-types-generics-fallback.patch"
      return 0
    fi
    python3 - "$target" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
text = path.read_text()

anchors = [
    """        if (preg_match('/^(list|array)\\s*</i', trim($decl))) {
            return new self(self::TYPE_ARRAY);
        }
        $regex = """,
    """        if (substr($decl, -2) === '[]') {
            $type = self::fromDecl(substr($decl, 0, -2));

            return new self(self::TYPE_ARRAY, [$type]);
        }
        $regex = """,
]
insert = """        if (preg_match('/^(list|array)\\s*</i', trim($decl))) {
            return new self(self::TYPE_ARRAY);
        }
        $pseudo = strtolower(trim($decl));
        if (in_array($pseudo, [
            'non-empty-string', 'literal-string', 'lowercase-string', 'uppercase-string',
            'class-string', 'interface-string', 'trait-string', 'html-escaped-string',
        ], true)) {
            return new self(self::TYPE_STRING);
        }
        if (in_array($pseudo, ['non-empty-array'], true)) {
            return new self(self::TYPE_ARRAY);
        }
        if (preg_match('/^(positive|negative|non-zero)-int$/', $pseudo)) {
            return new self(self::TYPE_LONG);
        }
        $regex = """

for anchor in anchors:
    if anchor in text:
        path.write_text(text.replace(anchor, insert, 1))
        break
else:
    sys.stderr.write("php-types-generics-fallback: anchor not found in Type.php\n")
    sys.exit(1)
PY
    echo "Applied php-types-generics-fallback.patch (overlay)"
    return 0
  fi
  if [[ "$(basename "$patch")" == "php-types-fromdecl-trailing-comma.patch" ]]; then
    apply_php_types_fromdecl_trailing_comma_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-docblock-trailing-text.patch" ]]; then
    apply_php_types_docblock_trailing_text_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-callable-return-strip.patch" ]]; then
    apply_php_types_callable_return_strip_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-generic-null-tail.patch" ]]; then
    apply_php_types_generic_null_tail_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-remove-type-empty-union.patch" ]]; then
    apply_php_types_remove_type_empty_union_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-incdec-type.patch" ]]; then
    apply_php_types_incdec_type_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-yield-from.patch" ]]; then
    apply_php_types_yield_from_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-throw-expr.patch" ]]; then
    apply_php_types_throw_expr_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-never-type.patch" ]]; then
    apply_php_types_never_type_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-anonymous-class-type.patch" ]]; then
    apply_php_types_anonymous_class_type_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-ns-func-call.patch" ]]; then
    apply_php_types_ns_func_call_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-types-arrow-function.patch" ]]; then
    apply_php_types_arrow_function_overlay
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
  if [[ "$(basename "$patch")" == "php-cfg-list-spread.patch" ]]; then
    apply_php_cfg_list_spread_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-new-first-class-callable.patch" ]]; then
    apply_php_cfg_new_first_class_callable_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-empty-list-assignment.patch" ]]; then
    apply_php_cfg_empty_list_assignment_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-spread.patch" ]]; then
    apply_php_cfg_spread_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-call-arg-site-clone.patch" ]]; then
    apply_php_cfg_call_arg_site_clone_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-simplifier-call-unpack.patch" ]]; then
    apply_php_cfg_simplifier_call_unpack_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-typed-class-const.patch" ]]; then
    apply_php_cfg_typed_class_const_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-class-const-flags.patch" ]]; then
    apply_php_cfg_class_const_flags_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-match.patch" ]]; then
    apply_php_cfg_match_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-property-type.patch" ]]; then
    apply_php_cfg_property_type_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-ctor-promotion.patch" ]]; then
    apply_php_cfg_ctor_promotion_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-ctor-promotion-readonly.patch" ]]; then
    apply_php_cfg_ctor_promotion_readonly_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-assignop-coalesce.patch" ]]; then
    apply_php_cfg_assignop_coalesce_overlay
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
  if [[ "$(basename "$patch")" == "php-cfg-halt-compiler.patch" ]]; then
    apply_php_cfg_halt_compiler_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-throw-expr.patch" ]]; then
    apply_php_cfg_throw_expr_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-readonly-function.patch" ]]; then
    apply_php_cfg_readonly_function_overlay
    return $?
  fi
  if [[ "$(basename "$patch")" == "php-cfg-property-readonly.patch" ]]; then
    apply_php_cfg_property_readonly_overlay
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
  if [[ "$(basename "$patch")" == "php-cfg-asymmetric-visibility.patch" ]]; then
    apply_php_cfg_asymmetric_visibility_overlay
    return $?
  fi
  if apply_patch_file_direct "$patch"; then
    return 0
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
    php-cfg-new-ctor-parens.patch)
      echo "Skip ${patch_name} (optional — stale hunk #6549; does not block throw-expr #6746)"
      return 0
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

if [[ "${1:-}" != "--verify-only" ]]; then
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
apply_patch "$PATCH_DIR/php-llvm-vector-get-address-space.patch"
apply_patch "$PATCH_DIR/php-llvm-token-type-kind-typo.patch"
apply_patch "$PATCH_DIR/php-llvm-x86-posix-fallback.patch"
repair_php_llvm_token_type_kind_typo_in_prelinked

# php-cfg before php-types: php-types-mixed-reserved.patch references Op\Type\Mixed_.
if [[ -d "$ROOT/vendor/ircmaxell/php-cfg" ]]; then
  # __TRAIT__ scope (traitStack overlay) must run before patches that can fail early (#3640).
  apply_php_cfg_magic_constants_overlay || true
  # PHP 8.3 `in` operator CFG node must survive optional patch failures (#4682, #4850).
  apply_php_cfg_in_operator_overlay || true
  apply_php_cfg_exit_two_arg_overlay || true
  apply_php_cfg_void_cast_overlay || true
  # PHP 8.3 typed class/trait constants must survive optional patch failures (#6012).
  apply_php_cfg_typed_class_const_overlay || true
  # listSpreadRhs on Assign must exist before optional patch failures abort the script (#6069, #4835).
  apply_php_cfg_list_spread_overlay
  # ++/-- overlays are hard-required — missing PostInc arms break compile (#6326, #6321).
  apply_php_cfg_incdec_expr_overlay
  apply_php_types_incdec_type_overlay
  # Spine uses match + ??= + union types — apply before optional patches that can abort under set -e.
  apply_php_cfg_match_overlay
  apply_php_cfg_assignop_coalesce_overlay
  apply_php_cfg_union_type_overlay
  apply_php_types_union_type_overlay || true
  # Readonly closure FLAG_READONLY must exist before any closure compile (#7464, #7428).
  apply_php_cfg_readonly_function_overlay || true
  # Throw expressions must survive optional patch failures (#6746, #5151).
  apply_php_cfg_throw_expr_overlay
  apply_php_types_throw_expr_overlay
  # Required for readonly property VM/JIT guards (#3149, #4518); apply before optional patches may fail.
  apply_php_cfg_property_readonly_overlay
  apply_patch "$PATCH_DIR/php-cfg-dollars-brace.patch"
  apply_patch "$PATCH_DIR/php-cfg-mixed-reserved.patch"
  apply_patch "$PATCH_DIR/php-cfg-nullsafe.patch"
  apply_patch "$PATCH_DIR/php-cfg-nullsafe-parser.patch"
  apply_patch "$PATCH_DIR/php-cfg-error-suppress-read.patch"
  apply_patch "$PATCH_DIR/php-cfg-error-suppress-simplifier.patch"
  apply_patch "$PATCH_DIR/php-cfg-simplifier-call-unpack.patch" || true
  apply_patch "$PATCH_DIR/php-cfg-strict-types.patch"
  apply_patch "$PATCH_DIR/php-cfg-trycatch.patch"
  apply_patch "$PATCH_DIR/php-cfg-goto-scope.patch"
  apply_php_cfg_process_assertions_overlay || true
  apply_patch "$PATCH_DIR/php-cfg-phi-resolver-null.patch"
  apply_patch "$PATCH_DIR/php-cfg-magic-constants.patch"
  apply_patch "$PATCH_DIR/php-cfg-magic-script-const.patch"
  apply_patch "$PATCH_DIR/php-cfg-magic-line.patch"
  apply_patch "$PATCH_DIR/php-cfg-switch-cond-property.patch"
  apply_patch "$PATCH_DIR/php-cfg-loop-resolver-nested.patch"
  apply_patch "$PATCH_DIR/php-cfg-loop-resolver-continue-switch-warning.patch"
  apply_patch "$PATCH_DIR/php-cfg-loop-resolver-break-outside-context.patch"
  apply_patch "$PATCH_DIR/php-cfg-no-arrow-function.patch"
  apply_patch "$PATCH_DIR/php-cfg-no-closure-preg-replace-callback.patch"
  apply_patch "$PATCH_DIR/php-cfg-property-type.patch"
  apply_php_cfg_enum_early_chain
  apply_patch "$PATCH_DIR/php-cfg-typed-class-const.patch"
  apply_patch "$PATCH_DIR/php-cfg-class-const-flags.patch"
  # typed-class-const overlay copies Const_.php without enum markers; restore (#6622).
  apply_php_cfg_enum_class_const_overlay || true
  apply_patch "$PATCH_DIR/php-cfg-asymmetric-visibility.patch"
  apply_patch "$PATCH_DIR/php-cfg-assertion-expr-property.patch"
  apply_php_cfg_yield_from_overlay
  apply_patch "$PATCH_DIR/php-cfg-incdec-expr.patch"
  apply_patch "$PATCH_DIR/php-cfg-yield-keyed.patch"
  apply_patch "$PATCH_DIR/php-cfg-match.patch"
  apply_patch "$PATCH_DIR/php-cfg-halt-compiler.patch"
  apply_php_cfg_compiler_halt_offset_overlay
  apply_patch "$PATCH_DIR/php-cfg-assignop-coalesce.patch"
  apply_patch "$PATCH_DIR/php-cfg-list-destruct-byref.patch"
  apply_patch "$PATCH_DIR/php-cfg-empty-list-assignment.patch" || true
  apply_patch "$PATCH_DIR/php-cfg-list-skip-slot.patch" || true
  apply_patch "$PATCH_DIR/php-cfg-list-spread.patch"
  apply_patch "$PATCH_DIR/php-cfg-first-class-callable.patch"
  apply_patch "$PATCH_DIR/php-cfg-anonymous-class.patch"
  apply_patch "$PATCH_DIR/php-cfg-new-first-class-callable.patch"
  apply_patch "$PATCH_DIR/php-cfg-arrow-function.patch"
  apply_patch "$PATCH_DIR/php-cfg-new-ctor-parens.patch"
  apply_php_cfg_anonymous_class_name_overlay || true
  apply_patch "$PATCH_DIR/php-cfg-enum.patch"
  apply_patch "$PATCH_DIR/php-cfg-enum-implements.patch"
  apply_patch "$PATCH_DIR/php-cfg-enum-class-method.patch"
  apply_patch "$PATCH_DIR/php-cfg-enum-abstract.patch"
  apply_patch "$PATCH_DIR/php-cfg-named-args.patch"
  apply_patch "$PATCH_DIR/php-cfg-call-time-pass-by-ref.patch"
  apply_patch "$PATCH_DIR/php-cfg-spread.patch"
  apply_patch "$PATCH_DIR/php-cfg-call-arg-site-clone.patch" || true
  apply_patch "$PATCH_DIR/php-cfg-never-type.patch"
  apply_patch "$PATCH_DIR/php-cfg-intersection-type.patch"
  apply_patch "$PATCH_DIR/php-cfg-instanceof-union.patch"
  apply_patch "$PATCH_DIR/php-cfg-union-type.patch"
  apply_patch "$PATCH_DIR/php-cfg-ctor-promotion.patch"
  apply_patch "$PATCH_DIR/php-cfg-ctor-promotion-readonly.patch"
  apply_patch "$PATCH_DIR/php-cfg-readonly-function.patch"
  apply_patch "$PATCH_DIR/php-cfg-property-readonly.patch"
  apply_php_cfg_asymmetric_set_visibility_parser_overlay
  apply_php_cfg_asymmetric_get_visibility_parser_overlay
  apply_php_cfg_global_typed_const_overlay
  apply_php_cfg_typed_function_static_overlay
  apply_patch "$PATCH_DIR/php-cfg-typed-function-static.patch"
  apply_patch "$PATCH_DIR/php-cfg-attribute-groups.patch"
  apply_patch "$PATCH_DIR/php-cfg-trait-use.patch"
  apply_php_cfg_trait_use_overlay
  apply_patch "$PATCH_DIR/php-cfg-throw-expr.patch"
  apply_patch "$PATCH_DIR/php-cfg-is-resource-no-assertion.patch"
fi

if [[ -d "$ROOT/vendor/ircmaxell/php-types" ]]; then
  apply_php_types_incdec_type_overlay
  apply_php_types_arrow_function_overlay
  apply_patch "$PATCH_DIR/php-types-binaryop-pow.patch"
  apply_patch "$PATCH_DIR/php-types-binaryop-coalesce.patch"
  apply_patch "$PATCH_DIR/php-types-cast-object.patch"
  apply_patch "$PATCH_DIR/php-types-cast-unset.patch"
  apply_patch "$PATCH_DIR/php-types-binaryop-spaceship.patch"
  apply_php_types_hex2bin_strict_overlay
  apply_patch "$PATCH_DIR/php-types-str-bool-fns.patch"
  apply_patch "$PATCH_DIR/php-types-str-incdec.patch"
  apply_patch "$PATCH_DIR/php-types-str-split-string-array.patch"
  apply_patch "$PATCH_DIR/php-types-readfile-int-false.patch"
  apply_patch "$PATCH_DIR/php-types-get-meta-tags-array-false.patch"
  apply_patch "$PATCH_DIR/php-types-array-combine-array-false.patch"
  apply_patch "$PATCH_DIR/php-types-stream-context-array-return.patch"
  apply_patch "$PATCH_DIR/php-types-strpbrk-string-false.patch"
  apply_patch "$PATCH_DIR/php-types-error-get-last-null.patch"
  apply_patch "$PATCH_DIR/php-types-crc32-int.patch"
  apply_patch "$PATCH_DIR/php-types-get-declared-functions.patch"
  apply_patch "$PATCH_DIR/php-types-gettimeofday-float.patch"
  apply_patch "$PATCH_DIR/php-types-round-float.patch"
  apply_patch "$PATCH_DIR/php-types-link-bool.patch"
  apply_patch "$PATCH_DIR/php-types-gc-enabled-bool.patch"
  apply_patch "$PATCH_DIR/php-types-dollars-brace.patch"
  apply_patch "$PATCH_DIR/php-types-missing-parent-no-echo.patch"
  apply_patch "$PATCH_DIR/php-types-mixed-reserved.patch"
  apply_patch "$PATCH_DIR/php-types-nullsafe.patch"
  apply_patch "$PATCH_DIR/php-types-static-var.patch"
  apply_patch "$PATCH_DIR/php-types-nullable-return.patch"
  # Never type needs mixed-reserved + nullable-return fromTypeDecl arms (#8738).
  apply_patch "$PATCH_DIR/php-types-never-type.patch"
  apply_patch "$PATCH_DIR/php-types-cfg-reference.patch"
  apply_patch "$PATCH_DIR/php-types-nullable-optype-return.patch"
  apply_patch "$PATCH_DIR/php-types-yield-from.patch"
  apply_patch "$PATCH_DIR/php-types-fromvalue-null.patch"
  apply_patch "$PATCH_DIR/php-types-doc-comment-string.patch"
  apply_patch "$PATCH_DIR/php-types-docblock-first-token.patch"
  apply_patch "$PATCH_DIR/php-types-array-shape.patch"
  apply_patch "$PATCH_DIR/php-types-generics-fallback.patch"
  apply_patch "$PATCH_DIR/php-types-generics-list-array.patch"
  apply_patch "$PATCH_DIR/php-types-iterable-generic.patch"
  apply_patch "$PATCH_DIR/php-types-docblock-trailing-text.patch"
  apply_patch "$PATCH_DIR/php-types-fromdecl-trailing-comma.patch"
  apply_patch "$PATCH_DIR/php-types-callable-return-strip.patch"
  apply_patch "$PATCH_DIR/php-types-generic-null-tail.patch"
  apply_patch "$PATCH_DIR/php-types-fromdecl-junk-fragments.patch"
  apply_patch "$PATCH_DIR/php-types-remove-type-empty-union.patch"
  apply_patch "$PATCH_DIR/php-types-anonymous-class-type.patch"
  apply_patch "$PATCH_DIR/php-types-ns-func-call.patch"
  apply_patch "$PATCH_DIR/php-types-arrow-function.patch"
  apply_patch "$PATCH_DIR/php-types-closure-unbound-this.patch"
  apply_patch "$PATCH_DIR/php-types-magic-script-const.patch"
  apply_patch "$PATCH_DIR/php-types-first-class-callable.patch"
  apply_patch "$PATCH_DIR/php-types-incdec-type.patch"
  apply_patch "$PATCH_DIR/php-types-intersection-type.patch"
  apply_patch "$PATCH_DIR/php-types-union-type.patch"
  apply_patch "$PATCH_DIR/php-types-throw-expr.patch"
  apply_php_types_fcc_overlay_final_repair
  apply_php_types_compiler_halt_offset_overlay
fi

if [[ -d "$ROOT/vendor/pre/plugin" ]]; then
  apply_patch "$PATCH_DIR/pre-plugin-parser-macros.patch"
  apply_patch "$PATCH_DIR/pre-plugin-autoload-prepend.patch"
fi

if ((${#APPLY_PATCH_FAILURES[@]} > 0)); then
  echo "apply-patches: ${#APPLY_PATCH_FAILURES[@]} patch(es) failed: ${APPLY_PATCH_FAILURES[*]}" >&2
  exit 1
fi
fi

# Language capabilities (#3802 throw expr, #3094 union, #3149 readonly) must survive harness tar-copy.
verify_critical_language_patches() {
  local parser="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
  local recon="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
  local prop="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/Property.php"
  local missing=()

  if [[ ! -d "$ROOT/vendor/ircmaxell/php-cfg" || ! -d "$ROOT/vendor/ircmaxell/php-types" ]]; then
    return 0
  fi
  if ! grep -qE 'parseExpr_Throw|Op\\Expr\\Throw_' "$parser" 2>/dev/null; then
    missing+=("php-cfg-throw-expr")
  fi
  if grep -q 'function parseEnumCase' "$parser" 2>/dev/null \
    && ! grep -q 'enumCaseHasExplicitValue' "$parser" 2>/dev/null; then
    missing+=("php-cfg-enum-case-explicit-value")
  fi
  local const_file="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Terminal/Const_.php"
  if [[ -f "$const_file" ]] \
    && grep -q 'function parseEnumCase' "$parser" 2>/dev/null \
    && ! grep -q 'enumCaseHasExplicitValue' "$const_file" 2>/dev/null; then
    missing+=("php-cfg-enum-case-explicit-value-Const_")
  fi
  if [[ -f "$const_file" ]] \
    && grep -q 'function parseStmt_Enum' "$parser" 2>/dev/null \
    && ! grep -q 'public bool \$isEnumCase = false' "$const_file" 2>/dev/null; then
    missing+=("php-cfg-enum-class-const-Const_")
  fi
  if grep -q 'function parseStmt_TraitUse' "$parser" 2>/dev/null; then
    if grep -A8 'function parseStmt_TraitUse' "$parser" 2>/dev/null | grep -q '// TODO'; then
      missing+=("php-cfg-trait-use")
    elif ! [[ -f "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/TraitUse.php" ]]; then
      missing+=("php-cfg-trait-use-Op")
    fi
  else
    missing+=("php-cfg-trait-use")
  fi
  if grep -q 'function parseStmt_Enum' "$parser" 2>/dev/null; then
    if ! grep -A35 'function parseStmt_Enum' "$parser" 2>/dev/null | grep -q 'Stmt\\TraitUse'; then
      missing+=("php-cfg-enum-trait-use")
    fi
    if ! grep -A35 'function parseStmt_Enum' "$parser" 2>/dev/null | grep -q 'Stmt\\ClassConst'; then
      missing+=("php-cfg-enum-class-const-Parser")
    fi
    if ! grep -A20 'function parseEnumCase' "$parser" 2>/dev/null | grep -q 'isEnumCase = true'; then
      missing+=("php-cfg-enum-case-isEnumCase")
    fi
  fi
  local vendor_type="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php"
  if [[ -f "$vendor_type" ]] && php_types_type_fromdecl_trailing_comma_corrupt "$vendor_type"; then
    missing+=("php-types-fromdecl-trailing-comma-corrupt")
  fi
  if ! grep -q 'instanceof Op\\Type\\Union_' "$recon" 2>/dev/null; then
    missing+=("php-types-union-type")
  elif ! php -l "$recon" >/dev/null 2>&1; then
    missing+=("php-types-union-type-syntax")
  fi
  if [[ -f "$vendor_type" ]] && ! grep -q 'instanceof CfgType\\Union_' "$vendor_type" 2>/dev/null; then
    missing+=("php-types-union-type-Type")
  fi
  if ! grep -q 'instanceof Op\\Type\\Intersection' "$recon" 2>/dev/null; then
    missing+=("php-types-intersection-type")
  fi
  if ! grep -q "case 'Expr_MagicScriptConst':" "$recon" 2>/dev/null; then
    missing+=("php-types-magic-script-const")
  fi
  if [[ -f "$vendor_type" ]] && ! grep -q 'instanceof CfgType\\Intersection' "$vendor_type" 2>/dev/null; then
    missing+=("php-types-intersection-type-Type")
  fi
  if ! grep -qE 'public \$readonly|propertyFlags' "$prop" 2>/dev/null; then
    missing+=("php-cfg-property-readonly-Property")
  fi
  if ! grep -qE 'propertyFlags = \$node->flags|\$cfgProp->readonly =|\$prop->readonly =|\$property->readonly =|->readonly = 0 !== \\(\\$node->flags & .*MODIFIER_READONLY\\)' "$parser" 2>/dev/null; then
    missing+=("php-cfg-property-readonly-Parser")
  fi
  local func_file="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Func.php"
  if [[ -f "$func_file" ]] && ! grep -q 'FLAG_READONLY' "$func_file" 2>/dev/null; then
    missing+=("php-cfg-readonly-function-Func")
  fi
  if grep -q 'function parseExpr_Closure' "$parser" 2>/dev/null \
    && ! grep -A25 'function parseExpr_Closure' "$parser" 2>/dev/null \
      | grep -q "compilerReadonlyFunction"; then
    missing+=("php-cfg-readonly-function-Parser")
  fi
  if grep -q 'function parseExpr_ArrowFunction' "$parser" 2>/dev/null \
    && ! grep -A25 'function parseExpr_ArrowFunction' "$parser" 2>/dev/null \
      | grep -q "compilerReadonlyFunction"; then
    missing+=("php-cfg-readonly-function-ArrowParser")
  fi
  if ! grep -q 'function extractAsymmetricSetVisibilityFromAttributes' "$parser" 2>/dev/null; then
    missing+=("php-cfg-asymmetric-set-visibility-Parser")
  fi
  if ! grep -q 'function extractAsymmetricGetVisibilityFromAttributes' "$parser" 2>/dev/null; then
    missing+=("php-cfg-asymmetric-get-visibility-Parser")
  fi
  if ! grep -q "case 'Expr_YieldFrom':" "$recon" 2>/dev/null; then
    missing+=("php-types-yield-from")
  fi
  if [[ ! -f "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/In_.php" ]]; then
    missing+=("php-cfg-in-operator-In_")
  fi
  if [[ -f "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Exit_.php" ]] \
    && ! grep -q 'public \$message' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Exit_.php" 2>/dev/null; then
    missing+=("php-cfg-exit-two-arg-Exit_")
  fi
  local assign_expr="$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Assign.php"
  if [[ -f "$assign_expr" ]] && ! grep -q 'listSpreadRhs' "$assign_expr" 2>/dev/null; then
    missing+=("php-cfg-list-spread-Assign")
  fi
  if grep -q 'function parseListAssignment' "$parser" 2>/dev/null \
    && ! grep -q 'listSpreadExcludedKeys = \$excludedKeys' "$parser" 2>/dev/null; then
    missing+=("php-cfg-list-spread-Parser")
  fi
  if [[ -f "$const_file" ]] \
    && ! grep -q 'public ?Type \$declaredType' "$const_file" 2>/dev/null; then
    missing+=("php-cfg-typed-class-const-Const_")
  fi
  if grep -q 'function parseStmt_ClassConst' "$parser" 2>/dev/null \
    && ! grep -q 'declaredType = null !== \$node->type' "$parser" 2>/dev/null; then
    missing+=("php-cfg-typed-class-const-Parser")
  fi
  if ! grep -q 'new Op\\Expr\\PostInc' "$parser" 2>/dev/null \
    || [[ ! -f "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/PostInc.php" ]]; then
    missing+=("php-cfg-incdec-expr")
  fi
  if ! grep -q "case 'Expr_PostInc':" "$recon" 2>/dev/null; then
    missing+=("php-types-incdec-type")
  fi
  if ! grep -q 'function resolveOp_Expr_ArrowFunction' "$recon" 2>/dev/null; then
    missing+=("php-types-arrow-function")
  fi
  if ! grep -q 'FirstClassCallable::KIND_METHOD' "$recon" 2>/dev/null; then
    missing+=("php-types-first-class-callable")
  elif grep -q 'return \[Type::array()\];' "$recon" 2>/dev/null; then
    missing+=("php-types-first-class-callable-Type-array-typo")
  elif ! grep -q 'new Type(Type::TYPE_ARRAY)' "$recon" 2>/dev/null; then
    missing+=("php-types-first-class-callable-TYPE_ARRAY")
  fi
  if ! grep -q "case 'Expr_Throw':" "$recon" 2>/dev/null; then
    missing+=("php-types-throw-expr")
  fi
  local type_php="$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php"
  if grep -q "case 'Expr_Throw':" "$recon" 2>/dev/null \
    && { ! grep -q 'function never(): self' "$type_php" 2>/dev/null \
      || ! grep -q 'instanceof CfgType\\Never_' "$type_php" 2>/dev/null \
      || ! grep -q "case 'never':" "$type_php" 2>/dev/null; }; then
    missing+=("php-types-never-type")
  fi
  local prelinked_recon="$ROOT/prelinked/bootstrap-vendor/sources/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php"
  if [[ -f "$prelinked_recon" ]] && ! grep -q "case 'Expr_PostInc':" "$prelinked_recon" 2>/dev/null; then
    missing+=("php-types-incdec-type-prelinked")
  fi
  if [[ -f "$prelinked_recon" ]] && ! grep -q 'FirstClassCallable::KIND_METHOD' "$prelinked_recon" 2>/dev/null; then
    missing+=("php-types-first-class-callable-prelinked")
  elif [[ -f "$prelinked_recon" ]] && grep -q 'return \[Type::array()\];' "$prelinked_recon" 2>/dev/null; then
    missing+=("php-types-first-class-callable-prelinked-Type-array-typo")
  fi
  if [[ -f "$prelinked_recon" ]] && ! grep -q "case 'Expr_Throw':" "$prelinked_recon" 2>/dev/null; then
    missing+=("php-types-throw-expr-prelinked")
  fi
  if [[ -f "$prelinked_recon" ]] && ! grep -q 'instanceof Op\\Type\\Union_' "$prelinked_recon" 2>/dev/null; then
    missing+=("php-types-union-type-prelinked")
  elif [[ -f "$prelinked_recon" ]] && ! php -l "$prelinked_recon" >/dev/null 2>&1; then
    missing+=("php-types-union-type-prelinked-syntax")
  fi
  if [[ -f "$prelinked_recon" ]] && ! grep -q 'instanceof Op\\Type\\Intersection' "$prelinked_recon" 2>/dev/null; then
    missing+=("php-types-intersection-type-prelinked")
  fi
  local prelinked_type="$ROOT/prelinked/bootstrap-vendor/sources/ircmaxell/php-types/lib/PHPTypes/Type.php"
  if [[ -f "$prelinked_type" ]] && ! grep -q 'instanceof CfgType\\Union_' "$prelinked_type" 2>/dev/null; then
    missing+=("php-types-union-type-Type-prelinked")
  fi
  if [[ -f "$prelinked_type" ]] && ! grep -q 'instanceof CfgType\\Intersection' "$prelinked_type" 2>/dev/null; then
    missing+=("php-types-intersection-type-Type-prelinked")
  fi
  if [[ -f "$prelinked_type" ]] \
    && grep -q "throw new \\\\LogicException('Unknown type encountered')" "$prelinked_type" 2>/dev/null; then
    missing+=("php-types-remove-type-empty-union-prelinked")
  fi
  if ((${#missing[@]} > 0)); then
    echo "apply-patches: critical language patch markers missing: ${missing[*]}" >&2
    echo "apply-patches: hint: php-types-incdec-type overlay anchor drift — see #6321" >&2
    echo "apply-patches: hint: php-types-first-class-callable overlay — run composer install && ./script/apply-patches.sh (#6932)" >&2
    exit 1
  fi
}
verify_critical_language_patches
