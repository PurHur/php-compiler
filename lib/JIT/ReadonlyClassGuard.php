<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\JIT\Builtin\CloneWithReinitRuntime;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\MethodVisibility;
use PHPCompiler\PropertyVisibility;
use PHPLLVM\Builder;

/**
 * Emit readonly-class and readonly-property instance write checks before JIT property stores (#1360, #3432).
 */
final class ReadonlyClassGuard
{
    /**
     * @param \PHPCompiler\JIT|null $jit Required for try/catch delivery of readonly Errors (#23665).
     */
    public static function emitBeforePropertyStore(
        Context $context,
        Variable $lvalue,
        ?Block $enclosingBlock,
        string $violation = 'modify',
        ?\PHPCompiler\JIT $jit = null
    ): void {
        if (null === $lvalue->objectPropertySlot) {
            return;
        }
        $objectType = $context->type->object;
        assert($objectType instanceof Object_);
        if (null === $lvalue->objectPropertyReceiver && null !== $lvalue->objectPropertySlot) {
            $lvalue->objectPropertyReceiver = $objectType->receiverForPropertySlot($lvalue->objectPropertySlot);
        }
        if (null === $lvalue->objectPropertyReceiver) {
            return;
        }
        $propName = $lvalue->objectPropertyName ?? 'property';
        if ('modify' === $violation && self::isConstructBlock($enclosingBlock)) {
            if (self::emitReadonlyInitScopeViolationIfNeeded($context, $objectType, $enclosingBlock, $propName)) {
                return;
            }

            return;
        }

        // Asymmetric set visibility (incl. implicit-final private(set)) is enforced by the
        // set-visibility guard — not here. Plain `final` is inheritance-only in php-src (#23683).
        $guardClassIds = array_values(array_unique(array_merge(
            $objectType->readonlyClassIds(),
            $objectType->readonlyPropertyClassIdsForProperty($propName),
            // Handler write-reject (DatePeriod @readonly): assign only — unset stays allowed (#26154).
            'modify' === $violation
                ? $objectType->writeRejectPropertyClassIdsForProperty($propName)
                : []
        )));
        if ([] === $guardClassIds) {
            return;
        }

        $obj = $lvalue->objectPropertyReceiver;
        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );

        $fn = BasicBlockHelper::parentFunction($context);
        $entry = BasicBlockHelper::tryGetInsertBlock($context);
        if (null === $entry) {
            // NestedJIT / pending-Error body emission can clear the insert block; resume
            // in an open block rather than Factory::basicBlock(null) (#26826).
            BasicBlockHelper::ensureOpenInsertBlock($context, 'readonly_guard_resume');
            $entry = BasicBlockHelper::tryGetInsertBlock($context);
            if (null === $entry) {
                return;
            }
            $fn = $entry->getParent();
            assert($fn instanceof \PHPLLVM\Value\Function_);
        }
        $storeBlock = $fn->appendBasicBlock('readonly_allow_store');
        $exitBlock = $fn->appendBasicBlock('readonly_guard_exit');

        $checkBlock = $entry;
        foreach ($guardClassIds as $i => $id) {
            $matchBlock = $fn->appendBasicBlock('readonly_match_'.$id);
            $nextCheck = $i + 1 < count($guardClassIds)
                ? $fn->appendBasicBlock('readonly_try_'.($i + 1))
                : $storeBlock;
            $context->builder->positionAtEnd($checkBlock);
            $expected = $context->constantFromInteger($id, 'int64');
            $isId = $context->builder->icmp(Builder::INT_EQ, $classId, $expected);
            $context->builder->branchIf($isId, $matchBlock, $nextCheck);

            $context->builder->positionAtEnd($matchBlock);
            $failBlock = $fn->appendBasicBlock('readonly_violation_'.$id);
            $context->builder->branch($failBlock);
            $context->builder->positionAtEnd($failBlock);
            $mayFirstInit = self::callerMayFirstInitReadonlyProperty(
                $context,
                $objectType,
                $enclosingBlock,
                $propName
            );
            // unset(): only uninitialized + declaring-class scope (zend_std_unset_property; #29131).
            // Mid-ctor init-then-unset must Error — do not treat !constructed as a free pass.
            if ('unset' === $violation) {
                $isUninit = self::emitPropertySlotIsUninitialized($context, $lvalue);
                $violatePlain = $fn->appendBasicBlock('readonly_unset_plain_'.$id);
                $declaringClass = $objectType->classNameForId($id);
                if ($mayFirstInit && null !== $isUninit) {
                    $context->builder->branchIf($isUninit, $storeBlock, $violatePlain);
                } elseif (null !== $isUninit) {
                    $violateUninitScope = $fn->appendBasicBlock('readonly_unset_scope_'.$id);
                    $context->builder->branchIf($isUninit, $violateUninitScope, $violatePlain);
                    $context->builder->positionAtEnd($violateUninitScope);
                    self::emitViolation(
                        $context,
                        $jit,
                        self::unsetReadonlyWrongScopeMessage(
                            $context,
                            $objectType,
                            $enclosingBlock,
                            $declaringClass,
                            $propName
                        )
                    );
                } else {
                    $context->builder->branch($violatePlain);
                }
                $context->builder->positionAtEnd($violatePlain);
                self::emitViolation(
                    $context,
                    $jit,
                    sprintf('Cannot unset readonly property %s::$%s', $declaringClass, $propName)
                );
                $checkBlock = $nextCheck;
                continue;
            }
            $constructed = $context->builder->load(
                $context->builder->structGep($obj, $objMap['constructed'])
            );
            $notConstructed = $context->builder->icmp(
                Builder::INT_EQ,
                $constructed,
                $context->getTypeFromString('int8')->constInt(0, false)
            );
            $reinitBlock = $fn->appendBasicBlock('readonly_reinit_'.$id);
            $violateBlock = $fn->appendBasicBlock('readonly_violate_'.$id);
            // Post-construction first init from declaring-class scope (#23475).
            $firstInitBlock = null;
            if ($mayFirstInit) {
                $firstInitBlock = $fn->appendBasicBlock('readonly_first_init_'.$id);
                $context->builder->branchIf($notConstructed, $storeBlock, $firstInitBlock);
                $context->builder->positionAtEnd($firstInitBlock);
                $isUninit = self::emitPropertySlotIsUninitialized($context, $lvalue);
                if (null !== $isUninit) {
                    $context->builder->branchIf($isUninit, $storeBlock, $reinitBlock);
                } else {
                    // Cannot inspect slot — keep post-construct deny/reinit path.
                    $context->builder->branch($reinitBlock);
                }
            } else {
                $context->builder->branchIf($notConstructed, $storeBlock, $reinitBlock);
            }
            $context->builder->positionAtEnd($reinitBlock);
            CloneWithReinitRuntime::ensureLinked($context);
            $reinitOk = CloneWithReinitRuntime::emitTryConsumePropertyName($context, $obj, $propName);
            $avizBlock = $fn->appendBasicBlock('readonly_reinit_aviz_'.$id);
            $context->builder->branchIf($reinitOk, $avizBlock, $violateBlock);
            $context->builder->positionAtEnd($avizBlock);
            // Clone-with unlocks readonly once; protected(set)/private(set) still apply (#29186).
            if (self::emitAsymmetricDenyOnReadonlyReinit(
                $context,
                $objectType,
                $enclosingBlock,
                $jit,
                $id,
                $propName
            )) {
                $checkBlock = $nextCheck;
                continue;
            }
            $context->builder->branch($storeBlock);
            $context->builder->positionAtEnd($violateBlock);
            $declaringClass = $objectType->classNameForId($id);
            $message = sprintf(
                'Cannot modify readonly property %s::$%s',
                $declaringClass,
                $propName
            );
            self::emitViolation($context, $jit, $message);
            $checkBlock = $nextCheck;
        }

        $context->builder->positionAtEnd($storeBlock);
        $context->builder->branch($exitBlock);
        $context->builder->positionAtEnd($exitBlock);
    }

    /**
     * After clone-with reinit consume succeeds, still enforce asymmetric set (#29186).
     *
     * @return bool true when a deny was emitted (caller must skip store / advance)
     */
    private static function emitAsymmetricDenyOnReadonlyReinit(
        Context $context,
        Object_ $objectType,
        ?Block $enclosingBlock,
        ?\PHPCompiler\JIT $jit,
        int $classId,
        string $propName
    ): bool {
        $readVis = $objectType->propertyVisibility($classId, $propName);
        $effectiveRead = PropertyVisibility::effectiveGetVisibility(
            $readVis,
            $objectType->propertyGetVisibility($classId, $propName)
        );
        $setVis = PropertyVisibility::effectiveSetVisibility(
            $readVis,
            $objectType->propertySetVisibility($classId, $propName)
        );
        if ($setVis === MethodVisibility::mask($effectiveRead)) {
            return false;
        }
        $declaringClass = $objectType->classNameForId($classId);
        $declaringLc = strtolower(ltrim($declaringClass, '\\'));
        $callerLc = null;
        $callerDisplay = null;
        if (null !== $enclosingBlock?->func?->class) {
            $callerDisplay = ltrim($enclosingBlock->func->class->value, '\\');
            $callerLc = strtolower($callerDisplay);
        } elseif ('' !== $context->scope->className) {
            $callerDisplay = ltrim($context->scope->className, '\\');
            $callerLc = strtolower($callerDisplay);
        }
        try {
            PropertyVisibility::assertWritable(
                $setVis,
                $callerLc,
                $declaringLc,
                $declaringClass,
                $propName,
                static function (string $child, string $parent) use ($objectType): bool {
                    $current = $child;
                    for ($depth = 0; $depth < 64; ++$depth) {
                        if ($current === $parent) {
                            return true;
                        }
                        $next = $objectType->parentClassLc($current);
                        if (null === $next) {
                            return false;
                        }
                        $current = $next;
                    }

                    return false;
                },
                MethodVisibility::mask($effectiveRead),
                $objectType->propertyAsymmetricExplicitRead($classId, $propName),
                $callerDisplay,
                true
            );
        } catch (\LogicException $e) {
            self::emitViolation($context, $jit, $e->getMessage());

            return true;
        }

        return false;
    }

    /**
     * True when the enclosing method's class may perform first post-construct init (#23475).
     *
     * php-src: Zend/zend_object_handlers.c / Zend/zend_readonly.c — any declaring-class
     * instance method may initialize an uninitialized readonly property once.
     * `__clone()` must not use this path: already-initialized readonly props are only
     * writable via the PHP 8.3+ reinit window (#23526).
     */
    private static function callerMayFirstInitReadonlyProperty(
        Context $context,
        Object_ $objectType,
        ?Block $enclosingBlock,
        string $propName
    ): bool {
        if (self::isCloneBlock($enclosingBlock)) {
            return false;
        }
        $callerClassId = self::callerClassId($context, $enclosingBlock);
        if (null === $callerClassId) {
            return false;
        }
        $meta = $objectType->instancePropertyVisibilityMeta($callerClassId, $propName);
        if (null === $meta || !$objectType->isPropertyReadonly($meta['declaringClassId'], $propName)) {
            return false;
        }

        return $callerClassId === $meta['declaringClassId'];
    }

    /**
     * Emit i1: property slot is uninitialized (null or TYPE_UNDEFINED __value__).
     *
     * @return \PHPLLVM\Value|null i1 predicate, or null when the slot cannot be inspected
     */
    private static function emitPropertySlotIsUninitialized(Context $context, Variable $lvalue): ?\PHPLLVM\Value
    {
        if (null === $lvalue->objectPropertySlot) {
            return null;
        }
        $voidPtr = $context->getTypeFromString('void*');
        $loaded = $context->builder->pointerCast(
            $context->builder->load($lvalue->objectPropertySlot),
            $voidPtr
        );
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $loaded,
            $voidPtr->constNull()
        );
        $fn = BasicBlockHelper::parentFunction($context);
        $checkType = $fn->appendBasicBlock('readonly_slot_type_check');
        $merge = $fn->appendBasicBlock('readonly_slot_uninit_merge');
        $entry = BasicBlockHelper::tryGetInsertBlock($context);
        if (null === $entry) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'readonly_slot_uninit_entry');
            $entry = BasicBlockHelper::tryGetInsertBlock($context);
            if (null === $entry) {
                return null;
            }
        }
        $context->builder->branchIf($isNull, $merge, $checkType);

        $context->builder->positionAtEnd($checkType);
        $valuePtr = $context->builder->pointerCast(
            $loaded,
            $context->getTypeFromString('__value__*')
        );
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $isUndef = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(\PHPCompiler\VM\Variable::TYPE_UNDEFINED, false)
        );
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($context->getTypeFromString('int1'));
        $phi->addIncoming($context->getTypeFromString('int1')->constInt(1, false), $entry);
        $phi->addIncoming($isUndef, $checkType);

        return $phi;
    }

    /**
     * Reject child-scope initialization of inherited readonly properties during __construct (#9714).
     *
     * @return bool true when a violation was emitted and the store must be skipped
     */
    private static function emitReadonlyInitScopeViolationIfNeeded(
        Context $context,
        Object_ $objectType,
        ?Block $enclosingBlock,
        string $propName
    ): bool {
        $receiverClassId = self::receiverClassId($context, $enclosingBlock);
        if (null === $receiverClassId) {
            return false;
        }
        $meta = $objectType->instancePropertyVisibilityMeta($receiverClassId, $propName);
        if (null === $meta || !$objectType->isPropertyReadonly($meta['declaringClassId'], $propName)) {
            return false;
        }
        $callerClassId = self::callerClassId($context, $enclosingBlock);
        if (null === $callerClassId || $callerClassId === $meta['declaringClassId']) {
            return false;
        }
        $declaringClass = $meta['declaringClassName'];
        $callerClass = $objectType->classNameForId($callerClassId);
        $message = sprintf(
            'Cannot initialize readonly property %s::$%s from scope %s',
            $declaringClass,
            $propName,
            $callerClass
        );
        // Pending + skip-store; thin AOT main aborts via ErrorRaise (#23665 / #3149).
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        ErrorRaise::emitRaise($context, $message);
        $fn = BasicBlockHelper::parentFunction($context);
        $exitBlock = $fn->appendBasicBlock('readonly_init_scope_exit');
        $context->builder->branch($exitBlock);
        $context->builder->positionAtEnd($exitBlock);

        return true;
    }

    /**
     * Deliver final/readonly write Error: catchable inside try, else pending + return (#23665).
     *
     * Uncaught pending uses {@see ErrorRaise} (LLVM globals + abort) — same as asymmetric
     * visibility (#4029). Early return stops further script ops before standalone abort.
     */
    private static function emitViolation(
        Context $context,
        ?\PHPCompiler\JIT $jit,
        string $message
    ): void {
        if (null !== $jit && [] !== $context->tryCatch->handlerStack) {
            TryCatchHelper::emitCatchableErrorMessage($context, $jit, $message);
            // getInsertBlock() throws on null ref — use tryGetInsertBlock (#26826).
            $insert = BasicBlockHelper::tryGetInsertBlock($context);
            if (null !== $insert && null === $insert->getTerminator()) {
                ErrorRaise::registerDeclarations($context);
                ErrorRaise::ensureLinked($context);
                ErrorRaise::emitRaise($context, $message);
                self::returnAfterPendingError($context);
            }

            return;
        }
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        ErrorRaise::emitRaise($context, $message);
        self::returnAfterPendingError($context);
    }

    /**
     * Stop the current LLVM function after recording a pending Error (#4029 shape).
     * Prefer typed returns over bare returnVoid for AOT verify (#4082).
     */
    private static function returnAfterPendingError(Context $context): void
    {
        $insert = BasicBlockHelper::tryGetInsertBlock($context);
        if (null === $insert) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'readonly_pending_return');
            $insert = BasicBlockHelper::tryGetInsertBlock($context);
        }
        $fn = null !== $insert ? $insert->getParent() : BasicBlockHelper::parentFunction($context);
        assert($fn instanceof \PHPLLVM\Value\Function_);
        if (BasicBlockHelper::isVoidLlvmFunctionValue($fn)) {
            $context->builder->returnVoid();

            return;
        }
        $fnType = BasicBlockHelper::llvmFunctionSignatureType($fn);
        if (null !== $fnType) {
            $returnType = $fnType->getReturnType();
            if (\PHPLLVM\Type::KIND_POINTER === $returnType->getKind()) {
                $context->builder->returnValue($returnType->constNull());

                return;
            }
            if (\PHPLLVM\Type::KIND_INTEGER === $returnType->getKind()) {
                $context->builder->returnValue($returnType->constInt(0, false));

                return;
            }
            $structName = $context->getStringFromType($returnType);
            if ('__value__' === $structName) {
                $slot = JitValueBox::alloc($context);
                $context->builder->call(
                    $context->lookupFunction('__value__writeNull'),
                    JitValueBox::pointer($context, $slot)
                );
                $context->builder->returnValue($context->builder->load($slot));

                return;
            }
        }
        $context->builder->returnVoid();
    }

    /**
     * Run $emitStore only when no pending readonly Error was recorded (#4875, #5720).
     * Call immediately after {@see emitBeforePropertyStore()} and before propertyStore.
     */
    public static function emitStoreUnlessPending(Context $context, callable $emitStore): void
    {
        ErrorRaise::ensureLinked($context);
        ErrorRaise::registerDeclarations($context);
        ReadonlyBridge::ensureLinked($context);
        ReadonlyBridge::registerDeclarations($context);

        // NestedJIT / ReadonlyRaise body emission can clear the insert block; store
        // unconditionally rather than fatal via Builder::getInsertBlock (#26756, #26826).
        $entry = BasicBlockHelper::tryGetInsertBlock($context);
        if (null === $entry) {
            $emitStore();

            return;
        }

        $fn = BasicBlockHelper::parentFunction($context);
        $doStore = $fn->appendBasicBlock('readonly_store_do');
        $skipStore = $fn->appendBasicBlock('readonly_store_skip');
        $done = $fn->appendBasicBlock('readonly_store_done');

        $i32 = $context->getTypeFromString('int32');
        $zero = $i32->constInt(0, false);
        $hasErrorPending = $context->builder->call(
            $context->lookupFunction('phpc_jit_error_has_pending')
        );
        $hasReadonlyPending = $context->builder->call(
            $context->lookupFunction('phpc_jit_has_pending_exception')
        );
        $errorPending = $context->builder->icmp(Builder::INT_NE, $hasErrorPending, $zero);
        $readonlyPending = $context->builder->icmp(Builder::INT_NE, $hasReadonlyPending, $zero);
        $isPending = $context->builder->or($errorPending, $readonlyPending);
        $context->builder->branchIf($isPending, $skipStore, $doStore);

        $context->builder->positionAtEnd($doStore);
        $emitStore();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($skipStore);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    /**
     * Uninitialized readonly unset from non-declaring scope (#29131).
     *
     * php-src: verify_readonly_initialization_access(..., "unset").
     */
    private static function unsetReadonlyWrongScopeMessage(
        Context $context,
        Object_ $objectType,
        ?Block $enclosingBlock,
        string $declaringClass,
        string $propName
    ): string {
        $callerClassId = self::callerClassId($context, $enclosingBlock);
        if (null === $callerClassId) {
            return sprintf(
                'Cannot unset readonly property %s::$%s from global scope',
                $declaringClass,
                $propName
            );
        }
        $callerClass = $objectType->classNameForId($callerClassId);

        return sprintf(
            'Cannot unset readonly property %s::$%s from scope %s',
            $declaringClass,
            $propName,
            $callerClass
        );
    }

    private static function isConstructBlock(?Block $block): bool
    {
        if (null === $block || null === $block->func) {
            return false;
        }
        $name = strtolower($block->func->name);

        return '__construct' === $name || str_ends_with($name, '::__construct');
    }

    private static function isCloneBlock(?Block $block): bool
    {
        if (null === $block || null === $block->func) {
            return false;
        }
        $name = strtolower($block->func->name);

        return '__clone' === $name || str_ends_with($name, '::__clone');
    }

    private static function callerClassId(Context $context, ?Block $enclosingBlock): ?int
    {
        if (null !== $enclosingBlock?->func?->class) {
            return $context->type->object->lookup($enclosingBlock->func->class->value);
        }
        if ('' !== $context->scope->className) {
            return $context->type->object->lookup($context->scope->className);
        }

        return null;
    }

    private static function receiverClassId(Context $context, ?Block $enclosingBlock): ?int
    {
        if (null !== $enclosingBlock?->func?->class) {
            return $context->type->object->lookup($enclosingBlock->func->class->value);
        }
        if (0 !== $context->scope->classId) {
            return $context->scope->classId;
        }

        return null;
    }

    private static function stringDataPtrFromLiteral(Context $context, string $message): \PHPLLVM\Value
    {
        // Use php_cstr_* rodata (MethodRegistry / M3Emit pattern) — heap __string__ value GEP
        // can yield a bad memcpy source for raise_logic_exception under MCJIT execute (#3149).
        return $context->builder->pointerCast(
            $context->constantFromString($message),
            $context->getTypeFromString('int8*')
        );
    }
}
