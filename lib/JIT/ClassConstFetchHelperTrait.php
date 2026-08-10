<?php

declare(strict_types=1);

/**
 * Extracted LLVM lowering bodies for {@see ClassConstFetchHelper} to shrink the helper entrypoint
 * file size for php-in-php migration work (#10200).
 */

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\PseudoClassScope;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ClassConstFetchRuntime;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\StringCaseCompare;
use PHPCompiler\JIT\ReadonlyBridge;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\ReflectionBuiltinHelper;
use PHPCfg\Operand;
use PHPLLVM\Builder;
use PHPLLVM\Value;

trait ClassConstFetchHelperTrait
{
    /**
     * Lower `$expr::class` when the class operand is a runtime expression (#4241).
     *
     * @return Value {@see __string__*}
     */
    public static function emitExprClassPseudoConst(Object_ $objectType, Variable $classVar): Value
    {
        $context = $objectType->jitContext();
        if (Variable::TYPE_OBJECT === $classVar->type) {
            return ReflectionBuiltinHelper::getClassName($context, $classVar);
        }
        $label = self::compileTimeNonObjectTypeLabel($classVar);
        if (null !== $label) {
            if (
                Variable::TYPE_NATIVE_BOOL === $classVar->type
                && \PHPCompiler\CompilerVersion::supportsClassPseudoConstValueNameTypeError()
                && null !== $classVar->compileTimeLong
            ) {
                $label = 0 !== $classVar->compileTimeLong ? 'true' : 'false';
            }
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitRaise(
                $context,
                \PHPCompiler\VM\EnumCaseSupport::formatClassPseudoConstTypeErrorMessage($label)
            );

            return $context->builder->load($context->constantStringFromString(''));
        }
        if (Variable::TYPE_VALUE === $classVar->type) {
            return self::emitValueBoxExprClassPseudoConst($objectType, $classVar);
        }

        throw new \LogicException('Unsupported operand for expression ::class in JIT');
    }

    /**
     * Lower `$operand::class` when the class operand is not a compile-time literal (#4179).
     *
     * @return Value {@see __string__*}
     */
    public static function emitClassPseudoConstStringValue(
        Object_ $objectType,
        Block $block,
        Variable $classVar
    ): Value {
        $literal = JitStringArg::compileTimeLiteral($classVar);
        if (null !== $literal) {
            $lcLiteral = strtolower(ltrim($literal, '\\'));
            // AOT/standalone: `static::class` must read runtime LSB (inherited methods +
            // forward_static_call), not fold to the declaring class (#20251, #19614).
            if (
                'static' === $lcLiteral
                && LateStaticBindingHelper::useRuntimeLateStatic($objectType->jitContext())
            ) {
                $classId = self::emitStaticKeywordClassId($objectType, $block);

                return self::emitClassNameStringFromClassId($objectType, $classId);
            }
            $resolved = self::resolveJitSelfClassPseudoConstDisplayName($objectType, $block, $literal)
                ?? self::resolveJitClassNameString($objectType, $block, $literal);
            $objectType->lookup($resolved);

            return $objectType->jitContext()->builder->load(
                $objectType->jitContext()->constantStringFromString($resolved)
            );
        }

        $context = $objectType->jitContext();
        $nameStr = JitStringArg::lowerDominating($context, $classVar, '::class class operand');
        $resolvedStr = self::emitScopeResolveClassNameString($objectType, $block, $nameStr, true);

        return self::emitClassPseudoConstFromResolvedName($objectType, $resolvedStr);
    }

    public static function fetchLiteralConstWithRuntimeClass(
        Object_ $objectType,
        Block $block,
        Variable $classVar,
        Operand $classOp,
        string $constName,
        ?\PHPCompiler\JIT $jit = null
    ): Variable {
        $classIdVal = self::emitResolveClassId($objectType, $block, $classVar, $classOp);
        $key = \PHPCompiler\ClassConstName::key($constName);
        $context = $objectType->jitContext();
        $resultSlot = JitValueBox::alloc($context);
        $fn = BasicBlockHelper::parentFunction($context);
        $entry = $context->builder->getInsertBlock();
        $merge = $fn->appendBasicBlock('class_const_var_cls_lit_merge');
        $fail = $fn->appendBasicBlock('class_const_var_cls_lit_fail');
        $i64 = $context->getTypeFromString('int64');
        $checkBlock = $entry;
        $hasCandidate = false;
        foreach ($objectType->allClassNamesById() as $id => $_) {
            $constants = $objectType->classConstantsForId($id);
            $entryData = null;
            foreach ($constants as [$constKey, $entry]) {
                if ($constKey === $key) {
                    $entryData = $entry;
                    break;
                }
            }
            if (null === $entryData) {
                continue;
            }
            $hasCandidate = true;
            $matchBlock = $fn->appendBasicBlock('class_const_var_cls_lit_match_'.$id.'_'.$key);
            $nextCheck = $fn->appendBasicBlock('class_const_var_cls_lit_try_'.$id.'_'.$key);
            $context->builder->positionAtEnd($checkBlock);
            $expectedId = $context->constantFromInteger($id, 'int64');
            $isId = $context->builder->icmp(Builder::INT_EQ, $classIdVal, $expectedId);
            $context->builder->branchIf($isId, $matchBlock, $nextCheck);
            $context->builder->positionAtEnd($matchBlock);
            if ($objectType->isTraitClass(strtolower(ltrim($objectType->classNameForId($id), '\\')))
                && !$objectType->isInTraitMethodScopeForTraitId($id, $block)) {
                $classLabel = $objectType->classNameForId($id);
                ErrorRaise::ensureLinked($context);
                ErrorRaise::emitRaise(
                    $context,
                    "Cannot access trait constant {$classLabel}::{$constName} directly"
                );
                $context->builder->branch($merge);
            } else {
                if (null !== $jit) {
                    ClassConstVisibilityJitGuard::emitBeforeFetch($objectType, $jit, $block, $id, $constName);
                    if ($objectType->isEnumClassId($id)) {
                        BackedEnumDuplicateJitGuard::emitBeforeEnumCaseFetch($objectType, $jit, $block, $id);
                    }
                }
                self::writeConstEntryForRuntime($context, $resultSlot, $entryData);
                $context->builder->branch($merge);
            }
            $checkBlock = $nextCheck;
        }
        $context->builder->positionAtEnd($checkBlock);
        if (!$hasCandidate) {
            throw new \LogicException("Undefined class constant for JIT: {$constName}");
        }
        $context->builder->branch($fail);

        $context->builder->positionAtEnd($fail);
        $displayClass = self::displayClassName($objectType, -1, $classOp);
        $message = "Undefined constant {$displayClass}::{$constName}";
        $context->builder->call(
            $context->lookupFunction('__compiler_jit_raise_logic_exception'),
            self::messageDataPtrForRuntime($context, $message),
            $context->constantFromInteger(strlen($message), 'size_t')
        );
        // abort — not returnVoid — this runs inside non-void methods (#19614).
        $context->builder->call($context->lookupFunction('abort'));

        $context->builder->positionAtEnd($merge);

        return new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $resultSlot
        );
    }

    public static function fetchDynamicWithRuntimeClass(
        Object_ $objectType,
        Block $block,
        Variable $classVar,
        Variable $nameVar,
        Operand $classOp
    ): Variable {
        $classIdVal = self::emitResolveClassId($objectType, $block, $classVar, $classOp);

        return self::fetchDynamicByClassIdValue($objectType, $classIdVal, $nameVar, $classOp, $block, null, $classVar);
    }

    public static function fetchDynamic(
        Object_ $objectType,
        int $classId,
        Variable $nameVar,
        Operand $classOp,
        ?Block $block = null,
        ?\PHPCompiler\JIT $jit = null
    ): Variable {
        $literal = JitStringArg::compileTimeLiteral($nameVar);
        if (null !== $literal) {
            if ('class' === strtolower($literal)) {
                $display = self::resolveJitSelfClassPseudoConstDisplayName(
                    $objectType,
                    $block,
                    $classOp instanceof Operand\Literal && \is_string($classOp->value) ? $classOp->value : null
                ) ?? self::displayClassName($objectType, $classId, $classOp);

                return self::classPseudoConst($objectType, $classId, $display);
            }
            if (null !== $block && null !== $jit) {
                ClassConstVisibilityJitGuard::emitBeforeFetch($objectType, $jit, $block, $classId, $literal);
                if ($objectType->isEnumClassId($classId)) {
                    BackedEnumDuplicateJitGuard::emitBeforeEnumCaseFetch($objectType, $jit, $block, $classId);
                }
            }

            try {
                return $objectType->classConstFetch(
                    $classId,
                    $literal,
                    $block,
                    $classOp instanceof Operand\Literal && \is_string($classOp->value) ? $classOp->value : null
                );
            } catch (\LogicException $e) {
                if (null === $jit || !str_starts_with($e->getMessage(), 'Undefined constant ')) {
                    throw $e;
                }
                // Runtime Error for missing / private-on-parent const (#19615).
                $context = $objectType->jitContext();
                ErrorRaise::registerDeclarations($context);
                ErrorRaise::ensureLinked($context);
                $nullLit = new \PHPCfg\Operand\Literal(null);
                $nullLit->type = \PHPTypes\Type::null();
                $dummy = Variable::fromLiteral($context, $nullLit);
                if ([] !== $context->tryCatch->handlerStack) {
                    TryCatchHelper::emitCatchableErrorMessage($context, $jit, $e->getMessage());
                } else {
                    ErrorRaise::emitRaise($context, $e->getMessage());
                }

                return $dummy;
            }
        }

        $context = $objectType->jitContext();
        $classIdVal = $context->constantFromInteger($classId, 'int64');

        return self::fetchDynamicByClassIdValue($objectType, $classIdVal, $nameVar, $classOp, $block, $jit);
    }

    /**
     * @return Value int64 class id
     */
    public static function emitResolveClassId(
        Object_ $objectType,
        Block $block,
        Variable $classVar,
        Operand $classOp
    ): Value {
        if (Variable::TYPE_OBJECT === $classVar->type) {
            $context = $objectType->jitContext();
            $objMap = $context->structFieldMap['__object__'];

            return $context->builder->load(
                $context->builder->structGep($classVar->value, $objMap['class_id'])
            );
        }
        $literal = JitStringArg::compileTimeLiteral($classVar);
        if (null !== $literal) {
            $lcLiteral = strtolower(ltrim($literal, '\\'));
            if (
                LateStaticBindingHelper::useRuntimeLateStatic($objectType->jitContext())
                && \in_array($lcLiteral, ['self', 'static', 'parent'], true)
            ) {
                $context = $objectType->jitContext();
                $nameStr = $context->builder->load(
                    $context->constantStringFromString($literal)
                );
                if ('static' === $lcLiteral) {
                    // Direct LSB class id — ensureLinked clears the insert block, so
                    // avoid name↔id roundtrip via emitResolveClassIdFromNameString (#19614).
                    return self::emitStaticKeywordClassId($objectType, $block);
                }
                $resolvedStr = self::emitScopeResolveClassNameString($objectType, $block, $nameStr);

                return self::emitResolveClassIdFromNameString($objectType, $resolvedStr);
            }
            $resolved = self::resolveJitClassNameString($objectType, $block, $literal);
            $id = $objectType->lookup($resolved);

            return $objectType->jitContext()->constantFromInteger($id, 'int64');
        }
        $context = $objectType->jitContext();
        $nameStr = JitStringArg::lowerDominating($context, $classVar, 'class constant class operand');
        $resolvedStr = self::emitScopeResolveClassNameString($objectType, $block, $nameStr);

        return self::emitResolveClassIdFromNameString($objectType, $resolvedStr);
    }

    /**
     * Runtime class id for literal `static::` / `static::class` (#19614, #20251).
     *
     * @return Value int64
     */
    public static function emitStaticKeywordClassIdForPseudoConst(Object_ $objectType, Block $block): Value
    {
        return self::emitStaticKeywordClassId($objectType, $block);
    }

    /**
     * Runtime class id for literal `static::` (#19614).
     *
     * Loads {@see LateStaticBindingGlobals::GLOBAL_CLASS_ID} with declaring-scope
     * fallback. Restores the builder insert block after ensureLinked (which clears it).
     *
     * @return Value int64
     */
    private static function emitStaticKeywordClassId(Object_ $objectType, Block $block): Value
    {
        $context = $objectType->jitContext();
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        // Global only — LateStaticBindingRuntime::ensureLinked clears insert / may link helpers.
        \PHPCompiler\JIT\Builtin\LateStaticBindingGlobals::ensureGlobal($context);
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);

        $runtimeId = LateStaticBindingHelper::emitLoadClassId($context);
        $scopeClass = self::jitScopeClassName($objectType, $block);
        if (null === $scopeClass || '' === $scopeClass) {
            return $runtimeId;
        }
        $fallbackId = $objectType->lookup($scopeClass);
        $i64 = $context->getTypeFromString('int64');
        $isZero = $context->builder->icmp(
            Builder::INT_EQ,
            $runtimeId,
            $i64->constInt(0, false)
        );

        return $context->builder->select(
            $isZero,
            $context->constantFromInteger($fallbackId, 'int64'),
            $runtimeId
        );
    }

    private static function fetchDynamicByClassIdValue(
        Object_ $objectType,
        Value $classIdVal,
        Variable $nameVar,
        Operand $classOp,
        ?Block $block = null,
        ?\PHPCompiler\JIT $jit = null,
        ?Variable $classVar = null
    ): Variable {
        return ClassConstFetchRuntime::fetchDynamicByClassIdValue(
            $objectType,
            $classIdVal,
            $nameVar,
            $classOp,
            $block,
            $jit,
            $classVar
        );
    }

    private static function classPseudoConst(Object_ $objectType, int $classId, ?string $displayName = null): Variable
    {
        $context = $objectType->jitContext();
        $lit = new Operand\Literal($displayName ?? $objectType->classNameForId($classId));
        $lit->type = \PHPTypes\Type::string();

        return Variable::fromLiteral($context, $lit);
    }

    public static function resolveJitScopeClassNameForBlock(Object_ $objectType, Block $block): ?string
    {
        return self::jitScopeClassName($objectType, $block);
    }

    /**
     * @return Value {@see __string__*}
     */
    public static function emitClassNameStringFromClassId(Object_ $objectType, Value $classId): Value
    {
        return self::classNameStringFromId($objectType, $classId);
    }

    /**
     * self::class in trait methods — composing class when invoked via user (#18879, #19629).
     */
    private static function resolveJitSelfClassPseudoConstDisplayName(
        Object_ $objectType,
        Block $block,
        ?string $classNameHint = null
    ): ?string {
        $lc = null !== $classNameHint ? strtolower(ltrim($classNameHint, '\\')) : null;
        if (null !== $lc && 'self' !== $lc) {
            return null;
        }
        $funcIsTrait = false;
        if (null !== $block->func?->class) {
            $funcIsTrait = $objectType->isTraitClass(strtolower(ltrim($block->func->class->value, '\\')));
        }
        if (!$funcIsTrait) {
            return null;
        }

        return self::jitComposingScopeClassName($objectType, $block);
    }

    private static function resolveJitClassNameString(Object_ $objectType, Block $block, string $className): string
    {
        $lc = strtolower($className);
        if ('self' === $lc) {
            $scope = self::jitComposingScopeClassName($objectType, $block);
            if (null === $scope) {
                PseudoClassScope::fatalNoActiveClassScope('self');
            }

            return $scope;
        }
        if ('static' === $lc) {
            $scope = self::jitLateStaticClassName($objectType, $block);
            if (null === $scope) {
                PseudoClassScope::fatalNoActiveClassScope('static');
            }

            return $scope;
        }
        if ('parent' === $lc) {
            $scope = self::jitComposingScopeClassName($objectType, $block);
            if (null === $scope) {
                PseudoClassScope::fatalNoActiveClassScope('parent');
            }
            $parent = $objectType->parentClassDisplayName($scope);
            if (null === $parent) {
                throw new \LogicException('parent:: used when class has no parent');
            }

            return $parent;
        }

        return $className;
    }

    /**
     * Trait methods — parent:: scope is the composing class, not the trait (#18878).
     */
    private static function jitComposingScopeClassName(Object_ $objectType, Block $block): ?string
    {
        $scope = self::jitScopeClassName($objectType, $block);
        if (null === $scope || null === $block->func?->class) {
            return $scope;
        }
        $funcClassLc = strtolower(ltrim($block->func->class->value, '\\'));
        if (!$objectType->isTraitClass($funcClassLc)) {
            return $scope;
        }
        $jitScope = $objectType->jitContext()->scope;
        $composing = $jitScope->traitComposingClassName ?? '';
        if ('' !== $composing && !$objectType->isTraitClass(strtolower(ltrim($composing, '\\')))) {
            return $composing;
        }
        if ($jitScope->classId > 0) {
            $fromId = $objectType->classNameForId($jitScope->classId);
            if ('' !== $fromId && !$objectType->isTraitClass(strtolower(ltrim($fromId, '\\')))) {
                return $fromId;
            }
        }
        $scopeName = $jitScope->className ?? '';
        if ('' !== $scopeName && !$objectType->isTraitClass(strtolower(ltrim($scopeName, '\\')))) {
            return $scopeName;
        }
        $called = $jitScope->calledClassName ?? '';
        if ('' !== $called) {
            $calledLc = strtolower(ltrim($called, '\\'));
            if ($calledLc !== $funcClassLc) {
                return $called;
            }
        }

        return $scope;
    }

    private static function jitScopeClassName(Object_ $objectType, Block $block): ?string
    {
        if (null !== $block->func && null !== $block->func->class) {
            return $block->func->class->value;
        }
        $scopeName = $objectType->jitContext()->scope->className ?? '';
        if ('' !== $scopeName) {
            return $scopeName;
        }

        return null;
    }

    public static function jitLateStaticClassNameForBlock(Object_ $objectType, Block $block): ?string
    {
        return self::jitLateStaticClassName($objectType, $block);
    }

    private static function jitLateStaticClassName(Object_ $objectType, Block $block): ?string
    {
        $called = $objectType->jitContext()->scope->calledClassName ?? '';
        $declaring = self::jitScopeClassName($objectType, $block);
        if (null === $declaring) {
            return '' !== $called ? $called : null;
        }

        return \PHPCompiler\VM\LateStaticBinding::resolveLateStaticClassLc(
            '' !== $called ? $called : null,
            $declaring
        );
    }

    /**
     * @return Value {@see __string__*}
     */
    private static function emitScopeResolveClassNameString(
        Object_ $objectType,
        Block $block,
        Value $nameStr,
        bool $forClassPseudoConst = false
    ): Value {
        $context = $objectType->jitContext();
        $scopeClass = self::jitComposingScopeClassName($objectType, $block);
        if (null === $scopeClass) {
            return $nameStr;
        }
        StringCaseCompare::ensureStrcasecmpLinked($context);
        $i32 = $context->getTypeFromString('int32');
        $result = $nameStr;

        $selfResolved = $forClassPseudoConst
            ? (self::resolveJitSelfClassPseudoConstDisplayName($objectType, $block, 'self') ?? $scopeClass)
            : $scopeClass;
        foreach (
            [
                ['self', $selfResolved],
                ['static', self::jitLateStaticClassName($objectType, $block) ?? $scopeClass],
            ] as [$keyword, $resolvedName]
        ) {
            $keyGlobal = $context->builder->load($context->constantStringFromString($keyword));
            $cmp = $context->builder->call(
                $context->lookupFunction('strcasecmp'),
                self::stringDataPtr($context, $nameStr),
                self::stringDataPtr($context, $keyGlobal)
            );
            $isKw = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
            $resolvedGlobal = $context->builder->load(
                $context->constantStringFromString($resolvedName)
            );
            $result = $context->builder->select($isKw, $resolvedGlobal, $result);
        }

        $parentName = $objectType->parentClassDisplayName($scopeClass);
        if (null !== $parentName) {
            $parentKey = $context->builder->load($context->constantStringFromString('parent'));
            $cmp = $context->builder->call(
                $context->lookupFunction('strcasecmp'),
                self::stringDataPtr($context, $nameStr),
                self::stringDataPtr($context, $parentKey)
            );
            $isParent = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
            $parentGlobal = $context->builder->load(
                $context->constantStringFromString($parentName)
            );
            $result = $context->builder->select($isParent, $parentGlobal, $result);
        }

        return $result;
    }

    /**
     * @return Value int64 class id
     */
    private static function emitResolveClassIdFromNameString(Object_ $objectType, Value $resolvedNameStr): Value
    {
        $context = $objectType->jitContext();
        StringCaseCompare::ensureStrcasecmpLinked($context);
        ErrorRaise::ensureLinked($context);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sentinel = $i64->constInt(-1, false);
        $result = $sentinel;
        foreach ($objectType->allDeclaredClassLowerNames() as $declLc) {
            $classId = $objectType->classIdForLowerName($declLc);
            if (null === $classId) {
                continue;
            }
            $lcGlobal = $context->builder->load(
                $context->constantStringFromString($declLc)
            );
            $cmp = $context->builder->call(
                $context->lookupFunction('strcasecmp'),
                self::stringDataPtr($context, $resolvedNameStr),
                self::stringDataPtr($context, $lcGlobal)
            );
            $isMatch = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
            $idVal = $context->constantFromInteger($classId, 'int64');
            $result = $context->builder->select($isMatch, $idVal, $result);
        }

        $fn = BasicBlockHelper::parentFunction($context);
        $entry = $context->builder->getInsertBlock();
        $ok = $fn->appendBasicBlock('class_const_resolve_id_ok');
        $fail = $fn->appendBasicBlock('class_const_resolve_id_fail');
        $merge = $fn->appendBasicBlock('class_const_resolve_id_merge');
        $isUnknown = $context->builder->icmp(Builder::INT_EQ, $result, $sentinel);
        $context->builder->branchIf($isUnknown, $fail, $ok);

        $context->builder->positionAtEnd($fail);
        // Catchable when `new $var` / class fetch is inside try (#27156, #4242).
        if ([] !== $context->tryCatch->handlerStack) {
            TryCatchHelper::emitCatchableClassError($context, 'Error', 'Class not found', null);
        } else {
            ErrorRaise::emitRaise($context, 'Class not found');
            $context->builder->call($context->lookupFunction('abort'));
        }

        $context->builder->positionAtEnd($ok);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);

        return $result;
    }

    /**
     * Return the reference name for `::class` after validating it names a declared class (#15645).
     *
     * @return Value {@see __string__*}
     */
    private static function emitClassPseudoConstFromResolvedName(Object_ $objectType, Value $resolvedNameStr): Value
    {
        $context = $objectType->jitContext();
        StringCaseCompare::ensureStrcasecmpLinked($context);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $known = $i64->constInt(0, false);
        foreach ($objectType->allDeclaredClassLowerNames() as $declLc) {
            if (null === $objectType->classIdForLowerName($declLc)) {
                continue;
            }
            $lcGlobal = $context->builder->load(
                $context->constantStringFromString($declLc)
            );
            $cmp = $context->builder->call(
                $context->lookupFunction('strcasecmp'),
                self::stringDataPtr($context, $resolvedNameStr),
                self::stringDataPtr($context, $lcGlobal)
            );
            $isMatch = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
            $known = $context->builder->select(
                $isMatch,
                $i64->constInt(1, false),
                $known
            );
        }

        $fn = BasicBlockHelper::parentFunction($context);
        $entry = $context->builder->getInsertBlock();
        $ok = $fn->appendBasicBlock('class_pseudo_const_ok');
        $fail = $fn->appendBasicBlock('class_pseudo_const_fail');
        $merge = $fn->appendBasicBlock('class_pseudo_const_merge');
        $isUnknown = $context->builder->icmp(Builder::INT_EQ, $known, $i64->constInt(0, false));
        $context->builder->branchIf($isUnknown, $fail, $ok);

        $context->builder->positionAtEnd($fail);
        $message = 'Unknown class for constant fetch';
        $context->builder->call(
            $context->lookupFunction('__compiler_jit_raise_logic_exception'),
            self::messageDataPtrForRuntime($context, $message),
            $context->constantFromInteger(strlen($message), 'size_t')
        );
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($ok);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);

        return $resolvedNameStr;
    }

    public static function writeEnumCaseConstEntryForRuntime(
        Object_ $objectType,
        Context $context,
        Value $slot,
        int $classId,
        string $caseKey
    ): void {
        $globalName = $objectType->ensureEnumCaseSingletonGlobal($classId, $caseKey);
        $global = $context->module->getNamedGlobal($globalName);
        if (null === $global) {
            throw new \LogicException("Missing enum case singleton global: {$globalName}");
        }
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );
        $obj = $context->builder->load($global);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $slot),
            $obj
        );
    }

    /**
     * @param array{type: int, value: int|float|bool|string|null} $entry
     */
    public static function writeConstEntryForRuntime(Context $context, Value $slot, array $entry): void
    {
        switch ($entry['type']) {
            case Variable::TYPE_NATIVE_LONG:
                JitValueBox::writeLong(
                    $context,
                    $slot,
                    $context->constantFromInteger((int) $entry['value'])
                );
                break;
            case Variable::TYPE_NATIVE_DOUBLE:
                $context->builder->call(
                    $context->lookupFunction('__value__writeDouble'),
                    JitValueBox::pointer($context, $slot),
                    $context->getTypeFromString('double')->constReal((float) $entry['value'])
                );
                break;
            case Variable::TYPE_NATIVE_BOOL:
                $context->builder->call(
                    $context->lookupFunction('__value__writeBool'),
                    JitValueBox::pointer($context, $slot),
                    $context->constantFromBool((bool) $entry['value'])
                );
                break;
            case Variable::TYPE_STRING:
                $context->builder->call(
                    $context->lookupFunction('__value__writeString'),
                    JitValueBox::pointer($context, $slot),
                    $context->builder->load($context->constantStringFromString((string) $entry['value']))
                );
                break;
            case Variable::TYPE_NULL:
                $context->builder->call(
                    $context->lookupFunction('__value__writeNull'),
                    JitValueBox::pointer($context, $slot)
                );
                break;
            case Variable::TYPE_HASHTABLE:
                if (isset($entry['global'])) {
                    $global = $context->module->getNamedGlobal($entry['global']);
                    if (null === $global) {
                        throw new \LogicException("Missing class constant hashtable global: {$entry['global']}");
                    }
                    $htPtr = $context->builder->load($global);
                    $context->refcount->addref($htPtr);
                    $context->builder->call(
                        $context->lookupFunction('__value__writeHashtable'),
                        JitValueBox::pointer($context, $slot),
                        $htPtr
                    );
                    break;
                }
                if (!isset($entry['vmTable']) || !$entry['vmTable'] instanceof \PHPCompiler\VM\HashTable) {
                    throw new \LogicException('Missing VM table for class constant array');
                }
                $htVar = HashTableHelper::variableFromVmHashTable($context, $entry['vmTable']);
                $htPtr = $context->helper->loadValue($htVar);
                $context->refcount->addref($htPtr);
                $context->builder->call(
                    $context->lookupFunction('__value__writeHashtable'),
                    JitValueBox::pointer($context, $slot),
                    $htPtr
                );
                break;
            case Variable::TYPE_OBJECT:
                if (!isset($entry['global'])) {
                    throw new \LogicException('Missing global for class constant object');
                }
                $global = $context->module->getNamedGlobal($entry['global']);
                if (null === $global) {
                    throw new \LogicException("Missing class constant object global: {$entry['global']}");
                }
                // Immortal module-global object: clear slot before writeObject (#3196, #4028).
                $context->builder->call(
                    $context->lookupFunction('__value__writeNull'),
                    JitValueBox::pointer($context, $slot)
                );
                $obj = $context->builder->load($global);
                $context->builder->call(
                    $context->lookupFunction('__value__writeObject'),
                    JitValueBox::pointer($context, $slot),
                    $obj
                );
                break;
            default:
                throw new \LogicException('Unsupported class constant type for dynamic JIT fetch');
        }
    }

    private static function displayClassName(Object_ $objectType, int $classId, Operand $classOp): string
    {
        if ($classOp instanceof Operand\Literal) {
            return $classOp->value;
        }
        if ($classId < 0) {
            return '*';
        }

        return $objectType->classNameForId($classId);
    }

    private static function stringDataPtr(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->structGep($strPtr, $map['value']);
    }

    public static function messageDataPtrForRuntime(Context $context, string $message): Value
    {
        $strPtr = $context->builder->load($context->constantStringFromString($message));
        $strMap = $context->structFieldMap['__string__'];

        return $context->builder->pointerCast(
            $context->builder->structGep($strPtr, $strMap['value']),
            $context->getTypeFromString('int8*')
        );
    }

    private static function compileTimeNonObjectTypeLabel(Variable $classVar): ?string
    {
        return match ($classVar->type) {
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_NATIVE_LONG => 'int',
            Variable::TYPE_NATIVE_DOUBLE => 'float',
            Variable::TYPE_NATIVE_BOOL => 'bool',
            Variable::TYPE_NULL => 'null',
            default => null,
        };
    }

    /**
     * @return Value {@see __string__*}
     */
    private static function emitValueBoxExprClassPseudoConst(Object_ $objectType, Variable $classVar): Value
    {
        $context = $objectType->jitContext();
        TypeErrorRaise::ensureLinked($context);
        $objMap = $context->structFieldMap['__object__'];
        $fn = BasicBlockHelper::parentFunction($context);
        $entry = $context->builder->getInsertBlock();
        $ok = $fn->appendBasicBlock('expr_class_pseudo_ok');
        $failEntry = $fn->appendBasicBlock('expr_class_pseudo_fail');
        $merge = $fn->appendBasicBlock('expr_class_pseudo_merge');

        $valuePtr = JitValueBox::valuePtrFromVariable($context, $classVar);
        $obj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        $objType = $context->getTypeFromString('__object__*');
        $isObject = $context->builder->icmp(
            Builder::INT_NE,
            $obj,
            $objType->constNull()
        );
        $context->builder->branchIf($isObject, $ok, $failEntry);

        $context->builder->positionAtEnd($ok);
        $classId = $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );
        $nameWhenObject = self::classNameStringFromId($objectType, $classId);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($failEntry);
        self::emitClassPseudoConstTypeErrorFromValueBox($context, $valuePtr);

        $context->builder->positionAtEnd($merge);

        return $nameWhenObject;
    }

    /**
     * @return Value {@see __string__*}
     */
    private static function classNameStringFromId(Object_ $objectType, Value $classId): Value
    {
        $context = $objectType->jitContext();
        $result = $context->builder->load($context->constantStringFromString(''));
        foreach ($objectType->allClassNamesById() as $id => $name) {
            $expected = $context->constantFromInteger($id, 'int64');
            $isId = $context->builder->icmp(Builder::INT_EQ, $classId, $expected);
            $candidate = $context->builder->load($context->constantStringFromString($name));
            $result = $context->builder->select($isId, $candidate, $result);
        }

        return $result;
    }

    private static function emitClassPseudoConstTypeErrorFromValueBox(Context $context, Value $valuePtr): void
    {
        $typeField = $context->structFieldMap['__value__']['type'];
        $kind = $context->builder->load(
            $context->builder->structGep($valuePtr, $typeField)
        );
        $i8 = $context->getTypeFromString('int8');
        $masked = $context->builder->and($kind, $i8->constInt(0x7f, false));
        $fn = BasicBlockHelper::parentFunction($context);
        $checkBlock = $context->builder->getInsertBlock();
        $useValueName = \PHPCompiler\CompilerVersion::supportsClassPseudoConstValueNameTypeError();
        // JIT __value__ type tags (Variable::TYPE_* & 0x7f) — not VM Variable::TYPE_*.
        $labels = [
            Variable::TYPE_STRING & 0x7f => 'string',
            Variable::TYPE_NATIVE_LONG => 'int',
            Variable::TYPE_NATIVE_DOUBLE => 'float',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_HASHTABLE & 0x7f => 'array',
        ];
        if (!$useValueName) {
            $labels[Variable::TYPE_NATIVE_BOOL] = 'bool';
        }
        $pending = count($labels) + ($useValueName ? 1 : 0);
        $i = 0;
        foreach ($labels as $tag => $typeName) {
            ++$i;
            $raiseBlock = $fn->appendBasicBlock('expr_class_pseudo_err_'.$typeName);
            $isLast = $i >= $pending;
            $nextCheck = $isLast
                ? $fn->appendBasicBlock('expr_class_pseudo_err_mixed')
                : $fn->appendBasicBlock('expr_class_pseudo_try_'.$typeName);
            $context->builder->positionAtEnd($checkBlock);
            $isTag = $context->builder->icmp(
                Builder::INT_EQ,
                $masked,
                $i8->constInt($tag, false)
            );
            $context->builder->branchIf($isTag, $raiseBlock, $nextCheck);
            $context->builder->positionAtEnd($raiseBlock);
            TypeErrorRaise::emitRaise(
                $context,
                \PHPCompiler\VM\EnumCaseSupport::formatClassPseudoConstTypeErrorMessage($typeName)
            );
            $context->builder->returnVoid();
            $checkBlock = $nextCheck;
        }
        if ($useValueName) {
            $raiseTrue = $fn->appendBasicBlock('expr_class_pseudo_err_true');
            $raiseFalse = $fn->appendBasicBlock('expr_class_pseudo_err_false');
            $mixed = $fn->appendBasicBlock('expr_class_pseudo_err_mixed_vn');
            $boolCheck = $fn->appendBasicBlock('expr_class_pseudo_bool_val');
            $context->builder->positionAtEnd($checkBlock);
            $isBool = $context->builder->icmp(
                Builder::INT_EQ,
                $masked,
                $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)
            );
            $context->builder->branchIf($isBool, $boolCheck, $mixed);
            $context->builder->positionAtEnd($boolCheck);
            $boolByte = JitValueBox::readBoolByte($context, $valuePtr);
            $isTrue = $context->builder->icmp(
                Builder::INT_NE,
                $boolByte,
                $i8->constInt(0, false)
            );
            $context->builder->branchIf($isTrue, $raiseTrue, $raiseFalse);
            $context->builder->positionAtEnd($raiseTrue);
            TypeErrorRaise::emitRaise(
                $context,
                \PHPCompiler\VM\EnumCaseSupport::formatClassPseudoConstTypeErrorMessage('true')
            );
            $context->builder->returnVoid();
            $context->builder->positionAtEnd($raiseFalse);
            TypeErrorRaise::emitRaise(
                $context,
                \PHPCompiler\VM\EnumCaseSupport::formatClassPseudoConstTypeErrorMessage('false')
            );
            $context->builder->returnVoid();
            $checkBlock = $mixed;
        }
        $context->builder->positionAtEnd($checkBlock);
        TypeErrorRaise::emitRaise(
            $context,
            \PHPCompiler\VM\EnumCaseSupport::formatClassPseudoConstTypeErrorMessage('mixed')
        );
        $context->builder->returnVoid();
    }
}

