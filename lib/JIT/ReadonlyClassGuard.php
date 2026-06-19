<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\JIT\Builtin\CloneWithReinitRuntime;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPLLVM\Builder;

/**
 * Emit readonly-class and readonly-property instance write checks before JIT property stores (#1360, #3432).
 */
final class ReadonlyClassGuard
{
    public static function emitBeforePropertyStore(
        Context $context,
        Variable $lvalue,
        ?Block $enclosingBlock,
        string $violation = 'modify'
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

        $guardClassIds = array_values(array_unique(array_merge(
            $objectType->readonlyClassIds(),
            $objectType->readonlyPropertyClassIdsForProperty($propName)
        )));
        if ([] === $guardClassIds) {
            return;
        }

        $obj = $lvalue->objectPropertyReceiver;
        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );

        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof \PHPLLVM\Value\Function_);
        $entry = $context->builder->getInsertBlock();
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
            $context->builder->branchIf($notConstructed, $storeBlock, $reinitBlock);
            $context->builder->positionAtEnd($reinitBlock);
            CloneWithReinitRuntime::ensureLinked($context);
            $reinitOk = CloneWithReinitRuntime::emitTryConsumePropertyName($context, $obj, $propName);
            $context->builder->branchIf($reinitOk, $storeBlock, $violateBlock);
            $context->builder->positionAtEnd($violateBlock);
            $declaringClass = $objectType->classNameForId($id);
            $message = sprintf(
                'unset' === $violation
                    ? 'Cannot unset readonly property %s::$%s'
                    : 'Cannot modify readonly property %s::$%s',
                $declaringClass,
                $propName
            );
            ReadonlyBridge::emitReadonlyViolation($context, $message);
            // Merge with allow path: pending flag + skip store (#3149, #4875). Avoid returnVoid here —
            // it breaks AOT LLVM verify and MCJIT uncaught readonly inc/dec (#4082).
            $context->builder->branch($exitBlock);
            $checkBlock = $nextCheck;
        }

        $context->builder->positionAtEnd($storeBlock);
        $context->builder->branch($exitBlock);
        $context->builder->positionAtEnd($exitBlock);
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
        ReadonlyBridge::emitReadonlyViolation($context, $message);
        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof \PHPLLVM\Value\Function_);
        $exitBlock = $fn->appendBasicBlock('readonly_init_scope_exit');
        $context->builder->branch($exitBlock);
        $context->builder->positionAtEnd($exitBlock);

        return true;
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

        $fn = BasicBlockHelper::parentFunction($context);
        $doStore = $fn->appendBasicBlock('readonly_store_do');
        $skipStore = $fn->appendBasicBlock('readonly_store_skip');
        $done = $fn->appendBasicBlock('readonly_store_done');
        $entry = $context->builder->getInsertBlock();

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

    private static function isConstructBlock(?Block $block): bool
    {
        if (null === $block || null === $block->func) {
            return false;
        }
        $name = strtolower($block->func->name);

        return '__construct' === $name || str_ends_with($name, '::__construct');
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
