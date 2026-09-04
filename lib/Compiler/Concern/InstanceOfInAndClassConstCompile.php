<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\ClassConstName;
use PHPCompiler\OpCode;
use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPCfg\Operand\Temporary;
use PHPTypes\Type;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\Compiler\PseudoClassTypeHintCompileCheck;
use PHPCompiler\Compiler\CompileFatal;

/**
 * instanceof / in-operator / class-const fetch compile (#36387 / #36403).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub can shrink toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Covers {@see compileInstanceOf}, {@see compileIn}, {@see compileClassConstFetch},
 * runtime enum const-fetch call-arg helpers, and related compile-time class-name rejects.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types (same as
 * IssetEmptyCallArgAndMultiCompile / CoalesceLeftAndEchoConcatPreludes).
 *
 * php-src: Zend/zend_compile.c (ZEND_INSTANCEOF / class constant fetch).
 */
trait InstanceOfInAndClassConstCompile
{
    protected function compileInstanceOf(Op\Expr\InstanceOf_ $expr, Block $block): array
    {
        $union = $expr->classUnion ?? null;
        if ($union instanceof Op\Type\Union_) {
            $names = $this->instanceofUnionNamesFromCfgType($union);
            $op = new OpCode(
                OpCode::TYPE_INSTANCEOF,
                $this->compileOperand($expr->result, $block, false),
                $this->compileOperand($expr->expr, $block, true),
                null
            );
            $op->instanceofUnionTypes = $this->encodeCatchTypeList($names);

            return [$op];
        }

        $op = new OpCode(
            OpCode::TYPE_INSTANCEOF,
            $this->compileOperand($expr->result, $block, false),
            $this->compileOperand($expr->expr, $block, true),
            $this->compileOperand($expr->class, $block, true)
        );
        $keyword = $this->instanceofLexicalScopeKeyword($expr->class);
        if (null !== $keyword) {
            $op->instanceofScopeKeyword = $keyword;
        }

        return [$op];
    }

    /**
     * Lexical instanceof RHS `self`/`parent`/`static` after php-cfg rewrite (#31729).
     *
     * Class methods already lower `self`/`parent` to the FQCN; trait bodies keep the
     * keyword. `static` stays the keyword in class and trait methods (late bind).
     * Do not walk {@see Operand::$original} — a rewritten Literal('CI') may
     * still carry a Name('self') from the parser.
     *
     * @return null|'parent'|'self'|'static'
     */
    private function instanceofLexicalScopeKeyword(?Operand $class): ?string
    {
        if (null === $class) {
            return null;
        }
        if ($class instanceof Operand\Literal && is_string($class->value)) {
            $lc = strtolower($class->value);
            if ('self' === $lc || 'parent' === $lc || 'static' === $lc) {
                return $lc;
            }

            return null;
        }
        if ($class instanceof Operand\Variable && $class->name instanceof Operand\Literal) {
            return $this->instanceofLexicalScopeKeyword($class->name);
        }

        return null;
    }

    /**
     * @return OpCode[]
     */
    protected function compileIn(Op\Expr\In_ $expr, Block $block): array
    {
        return [new OpCode(
            OpCode::TYPE_IN,
            $this->compileOperand($expr->result, $block, false),
            $this->compileInOperandSlot($expr->expr, $expr, 'needle', $block),
            $this->compileInOperandSlot($expr->haystack, $expr, 'haystack', $block),
        )];
    }

    /**
     * php-cfg may assign In_ needle/haystack operands to fresh temps disconnected from
     * preceding Array_/ClassConstFetch producers (#9676, #4682).
     */
    private function compileInOperandSlot(
        Operand $operand,
        Op\Expr\In_ $inExpr,
        string $role,
        Block $block
    ): int|string|null {
        if ('needle' === $role) {
            $varOperand = $this->unwrapVariableOperand($operand);
            if (null !== $varOperand) {
                return $this->compileOperand($varOperand, $block, true);
            }
        }
        $producer = $this->findInOperandProducer($operand, $inExpr, $role, $block);
        if (null !== $producer && null !== $producer->result) {
            return $this->compileOperand($producer->result, $block, true);
        }

        return $this->compileOperand($operand, $block, true);
    }

    private function findInOperandProducer(
        Operand $operand,
        Op\Expr\In_ $inExpr,
        string $role,
        Block $block
    ): ?Op\Expr {
        if (null === $block->orig) {
            return null;
        }
        $inIndex = null;
        foreach ($block->orig->children as $i => $child) {
            if ($child === $inExpr) {
                $inIndex = $i;
                break;
            }
        }
        if (null === $inIndex) {
            return null;
        }
        for ($i = $inIndex - 1; $i >= 0; --$i) {
            $child = $block->orig->children[$i];
            if ($child instanceof Op\Expr && null !== $child->result
                && $this->operandsReferToSameVariable($child->result, $operand)) {
                return $child;
            }
        }
        if ('haystack' === $role) {
            for ($i = $inIndex - 1; $i >= 0; --$i) {
                $child = $block->orig->children[$i];
                if ($child instanceof Op\Expr\Array_) {
                    return $child;
                }
            }

            return null;
        }
        if ($operand instanceof Operand\Variable || null !== $this->unwrapVariableOperand($operand)) {
            return null;
        }
        $arrayIndex = null;
        for ($i = $inIndex - 1; $i >= 0; --$i) {
            if ($block->orig->children[$i] instanceof Op\Expr\Array_) {
                $arrayIndex = $i;
                break;
            }
        }
        $arrayValueVars = [];
        if (null !== $arrayIndex) {
            /** @var Op\Expr\Array_ $arrayExpr */
            $arrayExpr = $block->orig->children[$arrayIndex];
            foreach ($arrayExpr->values as $valueOperand) {
                if ($valueOperand instanceof Operand\Temporary) {
                    $arrayValueVars[spl_object_id($valueOperand)] = true;
                }
            }
            for ($i = $arrayIndex - 1; $i >= 0; --$i) {
                $child = $block->orig->children[$i];
                if ($child instanceof Op\Expr\ClassConstFetch && null !== $child->result) {
                    if (!isset($arrayValueVars[spl_object_id($child->result)])) {
                        return $child;
                    }
                }
            }

            return null;
        }
        for ($i = $inIndex - 1; $i >= 0; --$i) {
            $child = $block->orig->children[$i];
            if ($child instanceof Op\Expr\ClassConstFetch) {
                return $child;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function instanceofUnionNamesFromCfgType(Op\Type\Union_ $union): array
    {
        $invalid = ['int', 'string', 'float', 'bool', 'array', 'callable', 'iterable', 'object', 'mixed', 'never', 'void', 'null'];
        $names = [];
        foreach ($union->types as $type) {
            if (!$type instanceof Op\Type\Literal) {
                $this->throwCompileLogic('instanceof union type members must be class or interface names');
            }
            $name = $type->name;
            if (in_array(strtolower($name), $invalid, true)) {
                $this->throwCompileLogic('Type '.$name.' cannot be used in instanceof');
            }
            $names[] = $name;
        }
        if (count($names) < 2) {
            $this->throwCompileLogic('instanceof union requires at least two class or interface names');
        }

        return $names;
    }

    /**
     * @return OpCode[]
     */
    protected function compileClassConstFetch(Op\Expr\ClassConstFetch $expr, Block $block): array
    {
        $this->rejectIllegalLiteralClassNameOperand($expr);
        $constName = $this->staticNameFromOperand($expr->name);
        $className = $this->staticNameFromOperand($expr->class);
        if (null !== $constName && null !== $className) {
            $lcClass = $this->resolveDefaultClassConstScope($className, $block);
            if (null !== $lcClass
                && $this->isCompileTimeEnumCaseConstantMember($lcClass, ClassConstName::key($constName))) {
                return $this->compileClassConstFetchRuntimeOpCodes($expr, $block, $expr->result);
            }
        }
        $folded = $this->tryFoldClassConstFetchDefault($expr, $block, true);
        if (null !== $folded) {
            $block->registerConstant($expr->result, $folded);

            return [];
        }

        return $this->compileClassConstFetchRuntimeOpCodes($expr, $block, $expr->result);
    }

    /**
     * @return list<OpCode>
     */
    protected function compileClassConstFetchRuntimeOpCodes(
        Op\Expr\ClassConstFetch $expr,
        Block $block,
        Operand $destOperand
    ): array {
        $this->rejectIllegalLiteralClassNameOperand($expr);
        $constName = $this->staticNameFromOperand($expr->name);
        $className = $this->staticNameFromOperand($expr->class);
        if (null !== $constName
            && 'class' === strtolower($constName)
            && null !== $className
            && !$this->pseudoClassInCompileScope($className, $block)) {
            $keyword = strtolower($className);
            // Free-function ::class uses zend_ensure_valid_class_fetch_type wording (#32227).
            // File-level still uses the historical global-scope diagnostic (#5024).
            if (in_array($keyword, ['self', 'parent', 'static'], true)
                && $this->compileScopeKnowsNoClassEntry($block)) {
                $sourceFile = $expr->getFile();
                if ('' === $sourceFile) {
                    $sourceFile = 'unknown';
                }
                $this->throwCompileError(
                    PseudoClassTypeHintCompileCheck::messageFor($keyword),
                    $sourceFile,
                    $expr->getLine()
                );
            }
            $this->throwCompileError(
                'Cannot use "'.$keyword.'" in the global scope'
            );
        }
        if (null !== $constName && 'class' === strtolower($constName)) {
            $this->rejectCompileTimeInvalidExprClassPseudoConst($expr, $block);
        }
        $op = new OpCode(
            OpCode::TYPE_CLASS_CONST_FETCH,
            $this->compileOperand($destOperand, $block, false),
            $this->compileClassNameOperand($expr->class, $block),
            $this->compileOperand($expr->name, $block, true)
        );
        // Use-site line for #[\Deprecated] class-const / enum-case notices (Zend zend_attributes.c / #29381).
        // Without this, FatalSite walks back to DECLARE_CLASS and cites the declaration line.
        $this->assignSourceMetadata($op, $expr);
        if (null !== $constName
            && 'class' === strtolower($constName)
            && ($expr->class instanceof Operand\Variable || $expr->class instanceof Operand\Temporary)) {
            $op->classConstFetchOnObject = true;
        }
        $scopeKeyword = $expr->getAttribute('phpcLexicalScopeKeyword');
        if (is_string($scopeKeyword) && '' !== $scopeKeyword) {
            $op->classConstFetchScopeKeyword = $scopeKeyword;
        }

        return [$op];
    }

    /**
     * Zend zend_compile.c — non-string literal class names are compile-time fatals (#29625).
     *
     * Parenthesized int/float scalars lower to {@see Operand\Literal} (unlike true/false/null,
     * which are ConstFetch → Temporary). `Foo::bar` / `(1)::class` both use this path.
     */
    protected function rejectIllegalLiteralClassNameOperand(Op\Expr\ClassConstFetch $expr): void
    {
        $class = $this->unwrapOperandChain($expr->class);
        if (!$class instanceof Operand\Literal || \is_string($class->value)) {
            return;
        }

        throw new CompileFatal(
            $expr->getFile() ?: 'unknown',
            max(1, $expr->getLine()),
            'Illegal class name'
        );
    }

    /**
     * Zend zend_compile.c — constant invalid `::class` operands are compile-time fatals (#17949).
     *
     * @return never
     */
    protected function rejectCompileTimeInvalidExprClassPseudoConst(
        Op\Expr\ClassConstFetch $expr,
        Block $block
    ): void {
        if (null !== $this->staticNameFromOperand($expr->class)) {
            return;
        }
        $classRoot = $this->unwrapOperandChain($expr->class);
        $varName = Block::resolveVariableName($classRoot);
        if (null !== $varName && '' !== $varName) {
            return;
        }
        if ($this->operandDerivesFromNew($expr->class, $block)) {
            return;
        }
        if ($this->cfgOperandReferencesScriptVariable($expr->class, $block)) {
            return;
        }
        $children = null !== $block->orig ? $block->orig->children : [];
        $producer = $this->findCfgExprProducerForOperand($expr->class, $children);
        if ($producer instanceof Op\Expr\New_
            || $producer instanceof Op\Expr\Closure
            || $producer instanceof Op\Expr\ArrowFunction
            || $producer instanceof Op\Expr\FuncCall
            || $producer instanceof Op\Expr\MethodCall
            || $producer instanceof Op\Expr\StaticCall
        ) {
            return;
        }
        $folded = null;
        if ($producer instanceof Op\Expr) {
            $folded = $this->tryFoldCompileTimeExprDefault($producer, $block, $children, true);
        }
        if (null === $folded) {
            $folded = $this->tryFoldCompileTimeOperandDefault($expr->class, $block, $children, true);
        }
        if (null === $folded) {
            return;
        }
        if (Variable::TYPE_STRING === $folded->type) {
            return;
        }
        throw new CompileFatal(
            $expr->getFile() ?: 'unknown',
            max(1, $expr->getLine()),
            \PHPCompiler\VM\EnumCaseSupport::classPseudoConstTypeErrorMessage($folded)
        );
    }

    protected function compileTimeValueTypeLabel(Variable $value): string
    {
        if (Variable::TYPE_OBJECT === $value->type || Variable::TYPE_ENUM_CASE === $value->type) {
            return 'object';
        }

        return match ($value->type) {
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_ARRAY => 'array',
            default => 'mixed',
        };
    }

    /**
     * @param list<Op> $cfgChildren
     */
    protected function findCfgExprProducerForOperand(Operand $operand, array $cfgChildren): ?Op\Expr
    {
        $root = $this->unwrapOperandChain($operand);
        foreach ($cfgChildren as $child) {
            if (!$child instanceof Op\Expr) {
                continue;
            }
            if (!property_exists($child, 'result') || !$this->operandsReferToSameVariable($child->result, $root)) {
                continue;
            }

            return $child;
        }

        return null;
    }

    protected function cfgOperandReferencesScriptVariable(Operand $operand, Block $block): bool
    {
        $children = null !== $block->orig ? $block->orig->children : [];
        $producer = $this->findCfgExprProducerForOperand($operand, $children);
        if ($producer instanceof Op\Expr) {
            return $this->cfgExprTreeReferencesScriptVariable($producer, $block);
        }
        $name = Block::resolveVariableName($this->unwrapOperandChain($operand));

        return null !== $name && '' !== $name;
    }

    protected function cfgExprTreeReferencesScriptVariable(Op\Expr $expr, Block $block): bool
    {
        if ($expr instanceof Op\Expr\BinaryOp) {
            return $this->cfgOperandReferencesScriptVariable($expr->left, $block)
                || $this->cfgOperandReferencesScriptVariable($expr->right, $block);
        }
        if ($expr instanceof Op\Expr\UnaryMinus
            || $expr instanceof Op\Expr\UnaryPlus
            || $expr instanceof Op\Expr\BitwiseNot
            || $expr instanceof Op\Expr\BooleanNot
        ) {
            return $this->cfgOperandReferencesScriptVariable($expr->expr, $block);
        }
        if ($expr instanceof Op\Expr\Cast) {
            return $this->cfgOperandReferencesScriptVariable($expr->expr, $block);
        }
        if ($expr instanceof Op\Expr\ArrayDimFetch) {
            return $this->cfgOperandReferencesScriptVariable($expr->var, $block)
                || (null !== $expr->dim && $this->cfgOperandReferencesScriptVariable($expr->dim, $block));
        }
        if ($expr instanceof Op\Expr\PropertyFetch) {
            return $this->cfgOperandReferencesScriptVariable($expr->var, $block);
        }
        if ($expr instanceof Op\Expr\ClassConstFetch) {
            return $this->cfgOperandReferencesScriptVariable($expr->class, $block);
        }
        if ($expr instanceof Op\Expr\ConstFetch) {
            return false;
        }

        return false;
    }

    /**
     * Runtime CLASS_CONST_FETCH when compile-time enum case fold fails (#4260, ext/standard/type.c).
     *
     * @return list<OpCode>
     */
    private function compileCallArgRuntimeEnumConstFetchOps(
        Operand $arg,
        Block $block,
        int $argIndex = 0,
        int $callOrdinal = 0,
        ?Op $cfgCallOp = null
    ): array {
        if (null === $block->orig) {
            return [];
        }
        if ($this->callArgOperandIsClosureValue($arg, $block)) {
            return [];
        }
        if (null !== $cfgCallOp && is_array($cfgCallOp->args ?? null)) {
            $callArg = $cfgCallOp->args[$argIndex] ?? null;
            if (null !== $callArg) {
                $callArgRoot = $this->unwrapOperandChain($callArg);
                if ($callArgRoot instanceof Op\Expr\ArrowFunction || $callArgRoot instanceof Op\Expr\Closure) {
                    return [];
                }
            }
        }
        $argRoot = $this->unwrapOperandChain($arg);
        if ($argRoot instanceof Op\Expr\ArrowFunction || $argRoot instanceof Op\Expr\Closure) {
            return [];
        }
        if (null !== $this->findInlineArrayProducerForCallArg($arg, $block, $cfgCallOp)) {
            return [];
        }
        if (
            null !== $cfgCallOp
            && (
                $this->nestedFuncCallFeedsDeadInlineCallArgZero($block, $cfgCallOp, $argIndex)
                || $this->nestedFuncCallFeedsDeadInlineCallArg($block, $cfgCallOp, $argIndex)
            )
        ) {
            return [];
        }
        // register_shutdown_function(fn(...), E::A) — arg #0 is hoisted Closure, not enum prelude (#5751).
        if (
            null !== $cfgCallOp
            && 0 === $argIndex
            && 'register_shutdown_function' === $this->resolveCfgFuncCallName($cfgCallOp)
        ) {
            foreach ($this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp) as $producer) {
                if ($producer instanceof Op\Expr\Closure || $producer instanceof Op\Expr\ArrowFunction) {
                    return [];
                }
            }
        }
        $fetch = null;
        foreach ($block->orig->children as $child) {
            if ($child instanceof Op\Expr\ClassConstFetch
                && $this->operandsReferToSameVariable($child->result, $arg)) {
                $fetch = $child;
                break;
            }
        }
        if (!$fetch instanceof Op\Expr\ClassConstFetch) {
            $fetch = $this->enumConstFetchForCallOrdinal($block, $callOrdinal, $argIndex);
        }
        if (!$fetch instanceof Op\Expr\ClassConstFetch) {
            $callSite = $this->findCfgCallSiteForArg($block->orig->children, $arg, $cfgCallOp);
            if (null !== $callSite) {
                [$callOp, $siteArgIndex] = $callSite;
                $fetches = $this->precedingCallArgClassConstFetchesBeforeCfgOp($block->orig->children, $callOp, $block);
                $fetch = $this->precedingClassConstFetchForCallArgIndex($callOp, $siteArgIndex, $fetches);
                if (!$fetch instanceof Op\Expr\ClassConstFetch) {
                    $fetch = $this->classConstFetchForHoistedDeadPrelude($callOp, $siteArgIndex, $block);
                }
            }
        }
        if (!$fetch instanceof Op\Expr\ClassConstFetch) {
            $root = $this->unwrapOperandChain($arg);
            if ($root instanceof Op\Expr\ClassConstFetch) {
                $fetch = $root;
            }
        }
        if (!$fetch instanceof Op\Expr\ClassConstFetch) {
            return [];
        }
        $constName = $this->staticNameFromOperand($fetch->name);
        $className = $this->staticNameFromOperand($fetch->class);
        if (null === $constName || null === $className) {
            return [];
        }
        $lcClass = $this->resolveDefaultClassConstScope($className, $block);
        if (null === $lcClass || !$this->isCompileTimeEnumCaseConstantMember($lcClass, ClassConstName::key($constName))) {
            return [];
        }
        if (!$this->callArgNeedsRuntimeEnumConstFetch($arg, $fetch, $block, $cfgCallOp)) {
            return [];
        }

        return $this->compileClassConstFetchRuntimeOpCodes($fetch, $block, $arg);
    }

    /**
     * Guard ordinal/hoisted enum fetch injection — do not overwrite unrelated call-arg slots (#5637).
     */
    private function callArgNeedsRuntimeEnumConstFetch(
        Operand $arg,
        Op\Expr\ClassConstFetch $fetch,
        Block $block,
        ?Op $cfgCallOp = null
    ): bool {
        if ($this->callArgOperandIsClosureValue($arg, $block)) {
            return false;
        }
        if (null !== $cfgCallOp && null !== $block->orig && is_array($cfgCallOp->args ?? null)) {
            $callSite = $this->findCfgCallSiteForArg($block->orig->children, $arg, $cfgCallOp);
            if (null !== $callSite) {
                [$callOp, $siteArgIndex] = $callSite;
                $callArg = $callOp->args[$siteArgIndex] ?? null;
                if (null !== $callArg) {
                    $callArgRoot = $this->unwrapOperandChain($callArg);
                    if ($callArgRoot instanceof Op\Expr\BinaryOp) {
                        return false;
                    }
                    $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                        $block->orig->children,
                        $callOp
                    );
                    if (null !== $this->matchBooleanBinaryOpInlineCallArgProducer($producers, $callArg)) {
                        return false;
                    }
                }
            }
        }
        $argRoot = $this->unwrapOperandChain($arg);
        if ($argRoot instanceof Op\Expr\PropertyFetch
            || $argRoot instanceof Op\Expr\NullsafePropertyFetch
            || $argRoot instanceof Op\Expr\NullsafeMethodCall) {
            return false;
        }
        // Guard ordinal/hoisted binding: don't inject enum const fetch ops for scalar-typed call args.
        // php-cfg may create an unrelated temp (e.g. identical/compare result) that happens to align
        // with a dead enum ClassConstFetch statement (#9030).
        if (!$argRoot instanceof Op\Expr\ClassConstFetch && null !== $argRoot->type) {
            $kind = $argRoot->type->type;
            if (
                Type::TYPE_BOOLEAN === $kind
                || Type::TYPE_LONG === $kind
                || Type::TYPE_DOUBLE === $kind
                || Type::TYPE_STRING === $kind
                || Type::TYPE_ARRAY === $kind
                || Type::TYPE_NULL === $kind
            ) {
                return false;
            }
        }
        $root = $argRoot;
        // Compare/arithmetic on enum case — compile the full Expr_* producer, not bare fetch (#8766).
        if ($root instanceof Op\Expr\BinaryOp) {
            return false;
        }
        if ($this->operandsReferToSameVariable($fetch->result, $arg)) {
            return true;
        }
        if ($root instanceof Op\Expr\ClassConstFetch) {
            return $root === $fetch
                || $this->operandsReferToSameVariable($fetch->result, $root->result);
        }
        if (null === $block->orig) {
            return false;
        }
        $callSite = $this->findCfgCallSiteForArg($block->orig->children, $arg, $cfgCallOp);
        if (null === $callSite) {
            return false;
        }
        [$callOp, $siteArgIndex] = $callSite;
        $callArg = $callOp->args[$siteArgIndex] ?? null;
        if (null === $callArg) {
            return false;
        }
        if ($this->operandsReferToSameVariable($fetch->result, $callArg)) {
            return true;
        }
        $callRoot = $this->unwrapOperandChain($callArg);
        if ($callRoot instanceof Op\Expr\ClassConstFetch) {
            return $callRoot === $fetch
                || $this->operandsReferToSameVariable($fetch->result, $callRoot->result);
        }

        // php-cfg dead prelude: ClassConstFetch stmt + distinct call-arg temp (#5933, #8725).
        return $this->isPositionalEnumCaseConstFetchForCallArg($fetch, $callOp, $siteArgIndex, $block);
    }

    /**
     * php-cfg may emit `E::A; f($unrelatedTemp)` with no CFG edge between fetch and arg (#5933, #8725).
     */
    private function isPositionalEnumCaseConstFetchForCallArg(
        Op\Expr\ClassConstFetch $fetch,
        Op $callOp,
        int $argIndex,
        Block $block
    ): bool {
        if (null === $block->orig) {
            return false;
        }
        $constName = $this->staticNameFromOperand($fetch->name);
        $className = $this->staticNameFromOperand($fetch->class);
        if (null === $constName || null === $className) {
            return false;
        }
        $lcClass = $this->resolveDefaultClassConstScope($className, $block);
        if (null === $lcClass || !$this->isCompileTimeEnumCaseConstantMember($lcClass, ClassConstName::key($constName))) {
            return false;
        }
        $children = $block->orig->children;
        $preceding = $this->precedingCallArgClassConstFetchesBeforeCfgOp($children, $callOp, $block);
        if ($this->precedingClassConstFetchForCallArgIndex($callOp, $argIndex, $preceding) === $fetch) {
            return true;
        }
        $hoisted = $this->classConstFetchForHoistedDeadPrelude($callOp, $argIndex, $block);

        return $hoisted === $fetch;
    }

    /**
     * Hoisted enum fetches must not bind to unrelated call-arg slots (pack('i', E::A); #8816, stream_set_timeout($fp, E::A); #6147).
     */
    private function isUnrelatedEnumFetchCallArg(?Operand $callArg, Op\Expr\ClassConstFetch $fetch): bool
    {
        if (null === $callArg) {
            return true;
        }
        if ($this->operandsReferToSameVariable($fetch->result, $callArg)) {
            return false;
        }
        $root = $this->unwrapOperandChain($callArg);
        if ($root instanceof Op\Expr\ClassConstFetch) {
            return $root !== $fetch
                && !$this->operandsReferToSameVariable($fetch->result, $root->result);
        }

        return true;
    }

    private function compileStringLiteralSlot(string $value, Block $block): int
    {
        $var = new Variable(Variable::TYPE_STRING);
        $var->string($value, true);
        $operand = new Temporary();
        $operand->type = Type::string();

        return $block->registerConstant($operand, $var);
    }

    private function compileIntegerLiteralSlot(int $value, Block $block): int
    {
        $var = new Variable(Variable::TYPE_INTEGER);
        $var->int($value);
        $operand = new Temporary();
        $operand->type = Type::int();

        return $block->registerConstant($operand, $var);
    }

    /**
     * Zend/php-src rejects file-scope `final const` below PHP 8.4 (#10324, #15185, #16859).
     */
    protected function rejectFinalGlobalTypedConstantIfUnsupported(Op\Terminal\Const_ $const): void
    {
        if (0 === ($const->flags & \PhpParser\Node\Stmt\Class_::MODIFIER_FINAL)) {
            return;
        }
        if (\PHPCompiler\CompilerVersion::supportsFinalGlobalTypedConstants()) {
            return;
        }
        $this->throwCompileError(\PHPCompiler\Ast\GlobalTypedConstRewriter::FINAL_GLOBAL_CONST_REJECT_MESSAGE);
    }

}
