<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ClassConstName;
use PHPCompiler\ClassConstVisibility;
use PHPCompiler\DnfType;
use PHPCompiler\Frame;
use PHPCompiler\GenericArrayTypeSpec;
use PHPCompiler\MethodVisibility;
use PHPCompiler\PropertyVisibility;
use PHPCompiler\JIT;
use PHPCompiler\VM;
use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPCompiler\Func;
use PHPCompiler\Printer;
use PHPCompiler\Runtime;
use PHPCompiler\CompileResult;

use SplObjectStorage;
use PHPCfg\Func as CfgFunc;
use PHPCfg\Op;
use PHPCfg\Block as CfgBlock;
use PHPCfg\ErrorSuppressBlock;
use PHPCfg\Operand;
use PHPCfg\Operand\BoundVariable;
use PHPCfg\Operand\Literal;
use PHPCfg\Operand\NullOperand;
use PHPCfg\Operand\Temporary;
use PHPCfg\Operand\Variable as CfgVariable;
use PHPCfg\Script;
use PHPTypes\Type;
use PHPCompiler\VM\AttributeSupport;
use PHPCompiler\VM\ClassConstExpr;
use PHPCompiler\VM\ClassConstMaterializer;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context as VMContext;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\EnumSupport;
use PHPCompiler\VM\DateTimeInterfaceSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ReferencableCheck;
use PHPCompiler\VM\TypeCheck;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\VariableFunctionCall;
use PHPCompiler\VM\ClassReadonly;
use PHPCompiler\VM\ClassFinal;
use PHPCompiler\VM\ClosureRichDisplayName;
use PHPCompiler\JIT\OperandName;
use PHPCompiler\Ast\AsymmetricVisibilityRewriter;
use PHPCompiler\Ast\FinalPromotedPropertyRewriter;
use PHPCompiler\Ast\LazyPropertyRewriter;
use PHPCompiler\Ast\GeneratorYieldSourceMarker;
use PHPCompiler\Cfg\OpSubBlockAccess;
use PHPCompiler\Compiler\AbstractMethodBodyCheck;
use PHPCompiler\Compiler\AbstractMethodVisibilityCheck;
use PHPCompiler\Compiler\AbstractPromotedPropertyCompileCheck;
use PHPCompiler\Compiler\InterfaceConstAmbiguityCheck;
use PHPCompiler\Compiler\InterfaceConstVisibilityCheck;
use PHPCompiler\Compiler\InterfaceMethodBodyCheck;
use PHPCompiler\Compiler\InterfaceMethodFinalCheck;
use PHPCompiler\Compiler\InterfaceMethodVisibilityCheck;
use PHPCompiler\Compiler\EnumAbstractMethodCompileCheck;
use PHPCompiler\Compiler\EnumBuiltinMethodRedeclareCheck;
use PHPCompiler\Compiler\ClassConstDuplicateCheck;
use PHPCompiler\Compiler\ClosureUseDuplicateCompileCheck;
use PHPCompiler\Compiler\EnumBackedCaseCheck;
use PHPCompiler\Compiler\EnumMagicMethodCheck;
use PHPCompiler\Compiler\EnumParentCompileCheck;
use PHPCompiler\Compiler\MagicMethodArityCheck;
use PHPCompiler\Compiler\MagicMethodParamTypeCheck;
use PHPCompiler\Compiler\MagicMethodReturnTypeCheck;
use PHPCompiler\Compiler\MagicMethodStaticCheck;
use PHPCompiler\Compiler\PseudoClassTypeHintCompileCheck;
use PHPCompiler\Compiler\DuplicateUnionMemberCompileCheck;
use PHPCompiler\Compiler\RedundantDnfArmCompileCheck;
use PHPCompiler\Compiler\RedundantDnfArmSubsetCompileCheck;
use PHPCompiler\Compiler\RedundantObjectClassUnionCompileCheck;
use PHPCompiler\Compiler\IntersectionTypeMemberCompileCheck;
use PHPCompiler\Compiler\FunctionStaticAnonymousClassCompileCheck;
use PHPCompiler\Compiler\NewWithoutParensCompileCheck;
use PHPCompiler\Compiler\NonAbstractMethodBodyCheck;
use PHPCompiler\Compiler\NonEnumBuiltinInterfaceCompileCheck;
use PHPCompiler\Compiler\ThrowInClassConstCompileCheck;
use PHPCompiler\Compiler\AsymmetricVisibilityCompileCheck;
use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\Compiler\AttributeClassRegistry;
use PHPCompiler\Compiler\AttributeConstantEvaluator;
use PHPCompiler\Compiler\AttributeEntry;
use PHPCompiler\Compiler\AttributeMetadata;
use PHPCompiler\Compiler\AttributeNames;
use PHPCompiler\Compiler\AttributeTargetValidator;
use PHPCompiler\Compiler\DeprecatedMetadata;
use PHPCompiler\Compiler\NoDiscardMetadata;
use PHPCompiler\Compiler\FinalClassConstCheck;
use PHPCompiler\Compiler\TraitClassConstConflictCheck;
use PHPCompiler\Compiler\FinalClassExtensionCheck;
use PHPCompiler\Compiler\ImplementsHierarchyCompileCheck;
use PHPCompiler\VM\ImplementsHierarchyRuntimeCheck;
use PHPCompiler\Compiler\FinalMethodOverrideCheck;
use PHPCompiler\Compiler\FinalPropertyOverrideCheck;
use PHPCompiler\Compiler\InterfaceImplementationCheck;
use PHPCompiler\Compiler\ParameterMetadata;
use PHPCompiler\VM\ReflectionTypeSupport;
use PHPCompiler\Compiler\GeneratorNeverReturnCompileCheck;
use PHPCompiler\Compiler\GeneratorStaticMethodCompileCheck;
use PHPCompiler\Compiler\ReadonlyClassCompileCheck;
use PHPCompiler\Compiler\SourceLocation;
use PHPCompiler\Compiler\TraitCollisionCheck;
use PHPCompiler\Compiler\ClassConstVisibilityInheritCheck;
use PHPCompiler\Compiler\PropertyVisibilityInheritCheck;
use PHPCompiler\Compiler\TypedClassConstInheritCheck;
use PHPCompiler\Compiler\TypedPropertyInheritCheck;
use PHPCompiler\Compiler\VariadicPromotedPropertyCompileCheck;
use PHPCompiler\Compiler\ClassCompileRegistry;
use PHPCompiler\Compiler\OverrideValidator;
use PHPCompiler\Web\ConstStringFolder;
use PHPCompiler\Web\IncludePathResolver;
use PHPCompiler\Web\Superglobals;

/**
/**
 * Class-constant declaration, compile-time fold, and typed-const rejects (#36387).
 *
 * Extracted from {@see ClassLikeAndStmtCompile}: {@code compileClassConstDeclaration}
 * through {@code rejectTypedInterfaceConstantIfUnsupported}. Move-only — mirrors
 * php-src {@code Zend/zend_compile.c} typed const / enum case handling; no new C ABI.
 */
trait CompileClassConstFoldAndTypedReject
{
    protected function compileClassConstDeclaration(Op\Terminal\Const_ $child, Block $result): void
    {
        $this->rejectStaticScopeInCompileTimeConstExpr($child->valueBlock, $child, $child->value);
        $constName = $this->staticNameFromOperand($child->name);
        $this->rejectReservedClassConstName($constName, $child);
        if (null !== $constName && null !== $this->compilingClassLc) {
            // Case-sensitive — const A and const a are distinct (#25929).
            $constKey = ClassConstName::key($constName);
            if (isset($this->compileTimeClassConstEmitted[$this->compilingClassLc][$constKey])) {
                // Idempotent re-parse when a JIT helper was already inlined from require_once (#9753, #1492).
                return;
            }
        }
        $valueSlot = $this->tryFoldClassConstValueSlot($child, $result);
        if (null === $valueSlot) {
            $this->compileOps($child->valueBlock->children, $result);
            $valueSlot = $this->compileOperand($child->value, $result, true);
        }
        $typeSlot = null;
        if (property_exists($child, 'declaredType') && null !== $child->declaredType) {
            if (null !== $constName) {
                $result->classConstDeclaredTypes[ClassConstName::key($constName)] = $child->declaredType;
            }
            if (!$this->cfgDeclaredTypeIsMixed($child->declaredType)) {
                $this->rejectTypedClassConstantIfUnsupported($child->name);
                $this->rejectTypedTraitConstantIfUnsupported($child->name);
                $this->rejectTypedInterfaceConstantIfUnsupported($child->name);
                $this->assertIntersectionTypeMembers($child->declaredType);
                $declared = $this->typeFromClassConstDecl($child);
                $typeSlot = $this->compileTypeConstrainedVariable($result, $declared, $child->declaredType);
                if (isset($result->constants[$valueSlot])) {
                    $this->verifyClassConstCompileTimeType(
                        $child->name,
                        $result->constants[$valueSlot],
                        $typeSlot,
                        $result
                    );
                }
            }
        }
        $constOp = new OpCode(
            OpCode::TYPE_DECLARE_CLASS_CONST,
            $this->compileOperand($child->name, $result, true),
            $valueSlot,
            $typeSlot
        );
        $constOp->classConstVisibilityFlags = property_exists($child, 'flags')
            ? (int) $child->flags
            : CfgFunc::FLAG_PUBLIC;
        if ($this->cfgTerminalConstIsEnumCase($child)) {
            $constOp->isEnumCaseDeclare = true;
            if (null !== $this->compilingClassLc) {
                $constName = $this->staticNameFromOperand($child->name);
                if (null !== $constName) {
                    $this->compileTimeEnumCaseConstNames[$this->compilingClassLc][ClassConstName::key($constName)] = true;
                }
            }
        }
        $constOp->deprecatedMetadata = DeprecatedMetadata::fromOp($child);
        $this->assignAttributeMetadata($constOp, $child);
        $this->assignSourceMetadata($constOp, $child);
        AttributeNames::assertAttributeMetaClassTargetOnly($constOp->attributeNames, 'class constant', $constOp->attributeEntries);
        AttributeNames::assertCompileTimeConstTargetOnly($constOp->attributeNames, 'class constant', $constOp->attributeEntries);
        AttributeNames::assertSensitiveParameterParamTargetOnly($constOp->attributeNames, 'class constant', $constOp->attributeEntries);
        AttributeNames::assertReturnTypeWillChangeMethodTargetOnly($constOp->attributeNames, 'class constant', $constOp->attributeEntries);
        AttributeNames::assertOverrideMethodTargetOnly($constOp->attributeNames, 'class constant', $constOp->attributeEntries);
        AttributeNames::assertDeprecatedTargetAllowed($constOp->attributeNames, 'class constant', $constOp->attributeEntries);
        $result->addOpCode($constOp);
        if (null !== $this->compilingClassLc && null !== $constName) {
            $this->compileTimeClassConstEmitted[$this->compilingClassLc][ClassConstName::key($constName)] = true;
        }
        if (null !== $this->compilingClassLc && isset($result->constants[$valueSlot])) {
            $constName = $this->staticNameFromOperand($child->name);
            if (null !== $constName) {
                $backing = new Variable();
                $backing->copyFrom($result->constants[$valueSlot]);
                if ($constOp->isEnumCaseDeclare) {
                    $stored = $this->compileTimeEnumCaseVar(
                        $this->compilingClassDisplayName ?? $this->compilingClassLc,
                        $constName,
                        $backing,
                        $this->compileTimeEnumBackedTypes[$this->compilingClassLc] ?? null
                    );
                } else {
                    $stored = new Variable();
                    $stored->copyFrom($backing);
                }
                // Case-sensitive storage key (#25910 fetch / #25929 declare).
                $constKey = ClassConstName::key($constName);
                $this->compileTimeClassConsts[$this->compilingClassLc][$constKey] = $stored;
                $this->compileTimeClassConstNames[$this->compilingClassLc][$constKey] = $constName;
                $this->compileTimeClassConstVisibility[$this->compilingClassLc][$constKey]
                    = ClassConstVisibility::mask($constOp->classConstVisibilityFlags);
                if (null !== $constOp->deprecatedMetadata) {
                    $this->compileTimeClassConstDeprecated[$this->compilingClassLc][$constKey]
                        = $constOp->deprecatedMetadata;
                }
            }
        }
    }

    /**
     * Distinguish enum `case` from user `const` when php-cfg isEnumCase is missing (#5832).
     * Bare `const` without visibility has flags=0 like cases; trust isEnumCase when set (#6878).
     */
    private function cfgTerminalConstIsEnumCase(Op\Terminal\Const_ $child): bool
    {
        if (property_exists($child, 'isEnumCase')) {
            return $child->isEnumCase;
        }
        if (null === $this->compilingClassLc
            || !array_key_exists($this->compilingClassLc, $this->compileTimeEnumBackedTypes)) {
            return false;
        }
        if (property_exists($child, 'declaredType') && null !== $child->declaredType) {
            return false;
        }
        $flags = property_exists($child, 'flags') ? (int) $child->flags : 0;
        // Enum cases cannot be protected/private/final; those must be user `const`.
        if (0 !== ($flags & (\PHPCfg\Func::FLAG_PROTECTED | \PHPCfg\Func::FLAG_PRIVATE | \PHPCfg\Func::FLAG_FINAL))) {
            return false;
        }

        // When php-cfg omits isEnumCase (#5832), try to distinguish backed enum `case` from user `const`.
        // Heuristic: backed enum cases must have a scalar literal backing value of the enum's backed type.
        $backedType = $this->compileTimeEnumBackedTypes[$this->compilingClassLc] ?? null;
        if (null === $backedType) {
            // Unit enums: enum cases have no backing scalar; default to legacy heuristic.
            return 0 === $flags;
        }
        $vm = $this->vmVariableFromCfgLiteralOperand($child->value);
        if (null === $vm) {
            return false;
        }
        if ('int' === $backedType) {
            return Variable::TYPE_INTEGER === $vm->type;
        }
        if ('string' === $backedType) {
            return Variable::TYPE_STRING === $vm->type;
        }

        return false;
    }

    /**
     * Compile-time enum case singleton for folds (default args, class const inits; #5514).
     */
    private function compileTimeEnumCaseVar(
        string $enumName,
        string $caseName,
        Variable $backing,
        ?string $backedType
    ): Variable {
        $entry = new ClassEntry(ltrim($enumName, '\\'));
        $entry->isEnum = true;
        $entry->backedType = $backedType;
        EnumSupport::ensureBuiltinEnumInterfaces($entry);

        return EnumCaseSupport::compileTimeCaseVariable($entry, $caseName, $backing);
    }

    private function compileTimeStoredValueIsEnumCaseBackingScalar(
        string $lcClass,
        string $lcConst,
        Variable $stored
    ): bool {
        if (!array_key_exists($lcClass, $this->compileTimeEnumBackedTypes)) {
            return false;
        }
        if (!isset($this->compileTimeEnumCaseConstNames[$lcClass][$lcConst])) {
            return false;
        }
        if (Variable::TYPE_OBJECT === $stored->type && EnumCaseSupport::isEnumCase($stored->toObject())) {
            return false;
        }

        return $stored->is(Variable::TYPE_INTEGER) || $stored->is(Variable::TYPE_STRING);
    }

    protected function tryFoldClassConstValueSlot(Op\Terminal\Const_ $terminal, Block $block): ?int
    {
        if (null !== $terminal->valueBlock && [] !== $terminal->valueBlock->children) {
            $children = $terminal->valueBlock->children;
            if (1 === \count($children) && $children[0] instanceof Op\Expr\Array_) {
                $vm = $this->tryBuildCompileTimeArrayFromExpr($children[0], $block, $children, true);
                if (null !== $vm) {
                    return $block->registerConstant(new Operand\Temporary(), $vm);
                }
            }
            if (1 === \count($children) && $children[0] instanceof Op\Expr\ClassConstFetch) {
                $vm = $this->tryFoldClassConstFetchDefault($children[0], $block, true);
                if (null !== $vm) {
                    return $block->registerConstant(new Operand\Temporary(), $vm);
                }
            }
            if (
                2 === \count($children)
                && $children[0] instanceof Op\Expr\ClassConstFetch
                && $children[1] instanceof Op\Expr\ArrayDimFetch
            ) {
                $vm = $this->tryFoldClassConstArraySubscriptExpr(
                    $children[0],
                    $children[1],
                    $block
                );
                if (null !== $vm) {
                    return $block->registerConstant(new Operand\Temporary(), $vm);
                }
            }
            if (1 === \count($children) && $children[0] instanceof Op\Expr) {
                $vm = $this->tryFoldCompileTimeExprDefault($children[0], $block, $children, true);
                if (null !== $vm) {
                    return $block->registerConstant(new Operand\Temporary(), $vm);
                }
            }
            // Multi-op const-expr (e.g. SCALE * 2, LABEL . "y", -A, A ?? 5): php-cfg
            // emits ConstFetch then the operator. Class bodies are hoisted before
            // DECLARE_GLOBAL_CONST, so runtime CONST_FETCH would miss the global (#23997).
            foreach ($children as $child) {
                if (!$child instanceof Op\Expr) {
                    continue;
                }
                if (!property_exists($child, 'result')
                    || !$this->operandsReferToSameVariable($child->result, $terminal->value)
                ) {
                    continue;
                }
                $vm = $this->tryFoldCompileTimeExprDefault($child, $block, $children, true);
                if (null !== $vm) {
                    return $block->registerConstant(new Operand\Temporary(), $vm);
                }
            }
            foreach ($children as $child) {
                if (!$child instanceof Op\Stmt\JumpIf) {
                    continue;
                }
                $vm = $this->tryFoldCompileTimeTernaryDefault(
                    $child,
                    $terminal->value,
                    $block,
                    $children,
                    true
                );
                if (null !== $vm) {
                    return $block->registerConstant(new Operand\Temporary(), $vm);
                }
            }
            $vm = $this->tryFoldClassConstMatchValueBlock(
                $terminal->valueBlock,
                $terminal->value,
                $block,
                $children
            );
            if (null !== $vm) {
                return $block->registerConstant(new Operand\Temporary(), $vm);
            }
        }
        $vm = $this->vmVariableFromCfgLiteralOperand($terminal->value);
        if (null === $vm) {
            return null;
        }

        return $block->registerConstant(new Operand\Temporary(), $vm);
    }

    /**
     * Historical fold for lowered match() in class constant initializers (#9987).
     *
     * Unreachable for honest php-src-strict: match is not a const-expr
     * ({@see ThrowInClassConstCompileCheck}, #24904). Kept so accidental CFG shapes
     * still resolve rather than silently emitting a wrong constant.
     *
     * @param list<Op> $defaultBlockChildren
     */
    protected function tryFoldClassConstMatchValueBlock(
        CfgBlock $entry,
        Operand $result,
        Block $block,
        array $defaultBlockChildren
    ): ?Variable {
        $subject = $this->extractClassConstMatchSubject($entry, $result, $block, $defaultBlockChildren);
        if (null === $subject) {
            return null;
        }

        return $this->evaluateClassConstMatchCfgBlock(
            $entry,
            $subject,
            $result,
            $block,
            $defaultBlockChildren,
            0
        );
    }

    private function extractClassConstMatchSubject(
        CfgBlock $entry,
        Operand $result,
        Block $block,
        array $defaultBlockChildren
    ): ?Variable {
        $start = 0;
        if (isset($entry->children[0]) && $this->isMatchSeedAssign($entry->children[0], $result)) {
            $start = 1;
        }
        $count = \count($entry->children);
        for ($i = $start; $i < $count; ++$i) {
            $child = $entry->children[$i];
            if (!$child instanceof Op\Expr\BinaryOp\Identical) {
                continue;
            }

            return $this->tryFoldCompileTimeOperandDefault(
                $child->left,
                $block,
                $defaultBlockChildren,
                true
            );
        }

        return null;
    }

    /**
     * @param list<Op> $defaultBlockChildren
     */
    private function evaluateClassConstMatchCfgBlock(
        CfgBlock $cfgBlock,
        Variable $subject,
        Operand $result,
        Block $block,
        array $defaultBlockChildren,
        int $startIndex
    ): ?Variable {
        $children = $cfgBlock->children;
        if (0 === $startIndex && isset($children[0]) && $this->isMatchSeedAssign($children[0], $result)) {
            $startIndex = 1;
        }
        $count = \count($children);
        for ($i = $startIndex; $i < $count; ++$i) {
            $child = $children[$i];
            if (
                $child instanceof Op\Expr\BinaryOp\Identical
                && isset($children[$i + 1])
                && $children[$i + 1] instanceof Op\Stmt\JumpIf
            ) {
                $jumpIf = $children[$i + 1];
                $pattern = $this->tryFoldCompileTimeOperandDefault(
                    $child->right,
                    $block,
                    $defaultBlockChildren,
                    true
                );
                if (null === $pattern) {
                    return null;
                }
                if ($subject->identicalTo($pattern)) {
                    return $this->evaluateClassConstMatchArmBlock(
                        $jumpIf->if,
                        $result,
                        $block,
                        $defaultBlockChildren
                    );
                }

                return $this->evaluateClassConstMatchCfgBlock(
                    $jumpIf->else,
                    $subject,
                    $result,
                    $block,
                    $defaultBlockChildren,
                    0
                );
            }
            if ($child instanceof Op\Terminal\Throw_) {
                return null;
            }
            if ($child instanceof Op\Expr\Assign && $this->operandsReferToSameVariable($child->var, $result)) {
                return $this->tryFoldCompileTimeOperandDefault(
                    $child->expr,
                    $block,
                    $defaultBlockChildren,
                    true
                );
            }
        }

        return null;
    }

    /**
     * @param list<Op> $defaultBlockChildren
     */
    private function evaluateClassConstMatchArmBlock(
        CfgBlock $armBlock,
        Operand $result,
        Block $block,
        array $defaultBlockChildren
    ): ?Variable {
        foreach ($armBlock->children as $child) {
            if ($child instanceof Op\Terminal\Throw_) {
                return null;
            }
            if ($child instanceof Op\Expr\Assign && $this->operandsReferToSameVariable($child->var, $result)) {
                return $this->tryFoldCompileTimeOperandDefault(
                    $child->expr,
                    $block,
                    $defaultBlockChildren,
                    true
                );
            }
        }

        return null;
    }

    private function isMatchSeedAssign(Op $op, Operand $result): bool
    {
        if (!$op instanceof Op\Expr\Assign) {
            return false;
        }
        if (!$this->operandsReferToSameVariable($op->var, $result)) {
            return false;
        }
        $lit = $this->vmVariableFromCfgLiteralOperand($op->expr);

        return null !== $lit && Variable::TYPE_STRING === $lit->type && '' === $lit->toString();
    }

    /**
     * Fold {@code self::ARR[1]} in class constant scalar expressions (#5465, zend_compile.c).
     */
    protected function tryFoldClassConstArraySubscriptExpr(
        Op\Expr\ClassConstFetch $fetch,
        Op\Expr\ArrayDimFetch $dimFetch,
        Block $block
    ): ?Variable {
        if (null === $dimFetch->dim) {
            return null;
        }
        $base = $this->tryFoldClassConstFetchDefault($fetch, $block);
        if (null === $base || !$base->is(Variable::TYPE_ARRAY)) {
            return null;
        }
        $dimVm = $this->vmVariableFromCfgLiteralOperand($dimFetch->dim);
        if (null === $dimVm) {
            return null;
        }
        $table = $base->toArray();
        if (!$table->keyExists($dimVm)) {
            return null;
        }
        $elem = $table->findVariable($dimVm, false);
        if (null === $elem) {
            return null;
        }
        $value = new Variable();
        $value->copyFrom($elem->resolveIndirect());

        return $value;
    }

    protected function typeFromClassConstDecl(Op\Terminal\Const_ $child): Type
    {
        if ($child->declaredType instanceof Op\Type\Literal) {
            return Type::fromDecl($child->declaredType->name);
        }
        if (null !== $child->declaredType) {
            return Type::fromTypeDecl($child->declaredType);
        }

        return Type::mixed();
    }

    protected function cfgDeclaredTypeIsMixed(?Op\Type $declaredType): bool
    {
        if (null === $declaredType) {
            return true;
        }
        if ($declaredType instanceof Op\Type\Mixed_) {
            return true;
        }

        return $declaredType instanceof Op\Type\Literal && 'mixed' === strtolower($declaredType->name);
    }

    protected function verifyClassConstCompileTimeType(
        Operand $nameOp,
        Variable $value,
        int $typeSlot,
        Block $block
    ): void {
        if (!isset($block->constants[$typeSlot])) {
            return;
        }
        $constName = $nameOp instanceof Operand\Literal ? (string) $nameOp->value : 'constant';
        $className = $this->compilingClassDisplayName;
        try {
            TypeCheck::assertClassConstantTypedValue(
                $value,
                $block->constants[$typeSlot],
                $constName,
                $className
            );
        } catch (\TypeError $e) {
            $this->throwCompileError($e->getMessage());
        }
    }

    protected function verifyGlobalConstCompileTimeType(
        Operand $nameOp,
        Variable $value,
        int $typeSlot,
        Block $block
    ): void {
        if (!isset($block->constants[$typeSlot])) {
            return;
        }
        $constName = $nameOp instanceof Operand\Literal ? (string) $nameOp->value : 'constant';
        try {
            TypeCheck::assertGlobalConstantTypedValue($value, $block->constants[$typeSlot], $constName);
        } catch (\TypeError $e) {
            $this->throwCompileError($e->getMessage());
        }
    }

    /**
     * Zend 8.2 rejects typed class constants at parse time; enable at 8.3+ forward/stable (#12798, #22705).
     */
    protected function rejectTypedClassConstantIfUnsupported(Operand $nameOp): void
    {
        if (CompilerVersion::supportsTypedClassConstants()) {
            return;
        }
        if (null === $this->compilingClassLc) {
            return;
        }
        if (
            $this->classCompileRegistry->isTrait($this->compilingClassLc)
            || $this->classCompileRegistry->isInterface($this->compilingClassLc)
        ) {
            return;
        }
        $constName = $this->staticNameFromOperand($nameOp) ?? 'constant';
        $this->throwCompileError(
            sprintf('syntax error, unexpected identifier "%s", expecting "="', $constName)
        );
    }

    /**
     * Zend 8.2 rejects typed trait constants at parse time; enable at 8.3+ (#5212).
     */
    protected function rejectTypedTraitConstantIfUnsupported(Operand $nameOp): void
    {
        if (CompilerVersion::supportsTypedTraitConstants()) {
            return;
        }
        if (
            null === $this->compilingClassLc
            || !$this->classCompileRegistry->isTrait($this->compilingClassLc)
        ) {
            return;
        }
        $constName = $this->staticNameFromOperand($nameOp) ?? 'constant';
        $this->throwCompileError(
            sprintf('syntax error, unexpected identifier "%s", expecting "="', $constName)
        );
    }

    /**
     * Zend 8.2 rejects typed interface constants at parse time; enable at 8.3+ forward/stable (#5980, #7042, #24917).
     */
    protected function rejectTypedInterfaceConstantIfUnsupported(Operand $nameOp): void
    {
        if (CompilerVersion::supportsInterfaceTypedConstants()) {
            return;
        }
        if (
            null === $this->compilingClassLc
            || !$this->classCompileRegistry->isInterface($this->compilingClassLc)
        ) {
            return;
        }
        $constName = $this->staticNameFromOperand($nameOp) ?? 'constant';
        $this->throwCompileError(
            sprintf('syntax error, unexpected identifier "%s", expecting "="', $constName)
        );
    }

}
