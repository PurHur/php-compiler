<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCompiler\Block;
use PHPCompiler\JIT\Variable;

/**
 * Property ++/-- compile paths: static / object / magic (#36387).
 *
 * Extracted from {@see \PHPCompiler\JIT}: {@code compileStaticPropertyIncDecOp}
 * through {@code compileMagicPropertyIncDecOp} so the hub stays under the 20k
 * size-budget target (Concern trait; same namespace as parent).
 *
 * php-src: Zend/zend_operators.c increment_function / decrement_function;
 * Zend/zend_object_handlers.c / Zend/zend_property_hooks.c for hooked props.
 */
trait PropertyIncDecCompile
{
    /** ++/-- on static hooked properties: get hook read, set hook write (#6319). */
    private function compileStaticPropertyIncDecOp(
        Variable $read,
        Variable $write,
        \PHPCfg\Operand $resultOp,
        bool $increment,
        bool $prefix
    ): void {
        if (null === $read->staticPropertyType) {
            throw new \LogicException('staticPropertyGlobal requires staticPropertyType');
        }
        $className = $read->staticPropertyHookClassLc ?? '';
        $propName = $read->objectPropertyName ?? '';
        $current = null;
        if ('' !== $className && '' !== $propName) {
            $hookVal = JIT\PropertyHookDispatch::tryEmitStaticPropertyGet(
                $this->context,
                $className,
                $propName,
                $this->context->jitEnclosingBlock
            );
            if (null !== $hookVal) {
                $current = new Variable(
                    $this->context,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VALUE,
                    $hookVal
                );
            }
        }
        if (null === $current) {
            $current = $read;
        }
        // Untyped statics are boxed `__value__*`. binaryOp+storeValueBox stored an alloca
        // pointer into the module global, so ++/-- vanished when the method returned (#32314).
        // php-src increment_function mutates the class static zval in place.
        if (Variable::TYPE_VALUE === $read->staticPropertyType && $current === $read) {
            $this->compileStaticPropertyValueBoxIncDecInPlace(
                $read,
                $write,
                $resultOp,
                $increment,
                $prefix
            );

            return;
        }
        if (!$prefix) {
            $this->assignOperand($resultOp, $current, true);
        }
        $arithOp = new OpCode($increment ? OpCode::TYPE_PLUS : OpCode::TYPE_MINUS);
        $oneVar = new Variable(
            $this->context,
            Variable::TYPE_NATIVE_LONG,
            Variable::KIND_VALUE,
            $this->context->constantFromInteger(1)
        );
        $newVal = $this->context->helper->binaryOp($arithOp, $current, $oneVar);
        if (
            !JIT\AsymmetricVisibilityGuard::emitBeforeStaticPropertyStore(
                $this->context,
                $this,
                $read,
                $this->context->jitEnclosingBlock
            )
            && !JIT\PropertyHookDispatch::emitStaticSetHookIfNeeded(
                $this->context,
                $write,
                $newVal,
                $this->context->jitEnclosingBlock,
                $this
            )
        ) {
            $this->context->type->object->staticPropertyStore(
                $read->staticPropertyGlobal,
                $newVal,
                $read->staticPropertyType,
                $read->staticPropertyInitGlobal
            );
        }
        if ($prefix) {
            $this->assignOperand($resultOp, $newVal, true);
        }
    }

    /**
     * Boxed static ++/-- writes the heap box the module global already points at (#32314).
     *
     * @see php-src Zend/zend_operators.c increment_function / decrement_function
     */
    private function compileStaticPropertyValueBoxIncDecInPlace(
        Variable $read,
        Variable $write,
        \PHPCfg\Operand $resultOp,
        bool $increment,
        bool $prefix
    ): void {
        if (JIT\AsymmetricVisibilityGuard::emitBeforeStaticPropertyStore(
            $this->context,
            $this,
            $read,
            $this->context->jitEnclosingBlock
        )) {
            return;
        }
        $heapPtr = JIT\JitValueBox::valuePtrFromVariable($this->context, $read);
        if (!$prefix) {
            $oldSlot = JIT\JitValueBox::alloc($this->context);
            $oldPtr = JIT\JitValueBox::pointer($this->context, $oldSlot);
            JIT\JitValueBox::copyIntoPointer($this->context, $oldPtr, $heapPtr);
            $oldVar = new Variable(
                $this->context,
                Variable::TYPE_VALUE,
                Variable::KIND_VARIABLE,
                $oldSlot
            );
            $this->assignOperand($resultOp, $oldVar, true);
        }
        $arithOp = new OpCode($increment ? OpCode::TYPE_PLUS : OpCode::TYPE_MINUS);
        $oneVar = new Variable(
            $this->context,
            Variable::TYPE_NATIVE_LONG,
            Variable::KIND_VALUE,
            $this->context->constantFromInteger(1)
        );
        $newVal = $this->context->helper->binaryOp($arithOp, $read, $oneVar);
        if (JIT\PropertyHookDispatch::emitStaticSetHookIfNeeded(
            $this->context,
            $write,
            $newVal,
            $this->context->jitEnclosingBlock,
            $this
        )) {
            if ($prefix) {
                $this->assignOperand($resultOp, $newVal, true);
            }

            return;
        }
        $cur = $this->readIncDecValueBoxLong($read, $heapPtr, $increment);
        JIT\JitIncDec::writeValueBoxIncDec($this->context, $read, $cur, $heapPtr, $increment);
        if (null !== $read->staticPropertyInitGlobal) {
            $this->context->builder->store(
                $this->context->getTypeFromString('int1')->constInt(1, false),
                $read->staticPropertyInitGlobal
            );
        }
        if ($prefix) {
            $newVar = new Variable(
                $this->context,
                Variable::TYPE_VALUE,
                Variable::KIND_VALUE,
                $heapPtr
            );
            $this->assignOperand($resultOp, $newVar, true);
        }
    }

    /** ++/-- on object properties: get/set hook dispatch or guard readonly (#6309, #3149). */
    private function compileObjectPropertyIncDecOp(
        Variable $read,
        \PHPCfg\Operand $resultOp,
        bool $increment,
        bool $prefix
    ): void {
        if (null === $read->objectPropertySlot || null === $read->objectPropertyType) {
            throw new \LogicException('objectPropertySlot requires objectPropertyType');
        }
        $current = null;
        if (
            null !== $read->objectPropertyReceiver
            && null !== $read->objectPropertyClassName
            && null !== $read->objectPropertyName
            && '' !== $read->objectPropertyName
        ) {
            $hookVal = JIT\PropertyHookDispatch::tryEmitPropertyGet(
                $this->context,
                $read->objectPropertyReceiver,
                $read->objectPropertyClassName,
                $read->objectPropertyName,
                $this->context->jitEnclosingBlock
            );
            if (null !== $hookVal) {
                $current = new Variable(
                    $this->context,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VALUE,
                    $hookVal
                );
            } elseif (JIT\PropertyHookDispatch::emitWriteOnlyVirtualReadGuard(
                $this->context,
                $this,
                $read->objectPropertyClassName,
                $read->objectPropertyName
            )) {
                return;
            }
        }
        if (
            null === $current
            && null !== $read->objectPropertyReceiver
            && null !== $read->objectPropertyClassName
            && null !== $read->objectPropertyName
            && '' !== $read->objectPropertyName
        ) {
            $magicClassId = $this->context->type->object->lookup($read->objectPropertyClassName);
            if (
                JIT\MagicMethodDispatch::propertyReadUsesMagicGetAtCompileTime(
                    $this->context,
                    $magicClassId,
                    $read->objectPropertyClassName,
                    $read->objectPropertyName,
                    $this->context->jitEnclosingBlock
                )
            ) {
                $magicFetched = JIT\MagicMethodDispatch::tryEmitMagicGet(
                    $this->context,
                    $read->objectPropertyReceiver,
                    $read->objectPropertyClassName,
                    $read->objectPropertyName,
                    $this->context->jitEnclosingBlock
                );
                if (null !== $magicFetched) {
                    $current = new Variable(
                        $this->context,
                        Variable::TYPE_VALUE,
                        Variable::KIND_VALUE,
                        $magicFetched
                    );
                }
            }
        }
        if (null === $current) {
            $current = $read;
        }
        if (!$prefix) {
            $this->assignOperand($resultOp, $current, true);
        }
        $arithOp = new OpCode($increment ? OpCode::TYPE_PLUS : OpCode::TYPE_MINUS);
        $oneVar = new Variable(
            $this->context,
            Variable::TYPE_NATIVE_LONG,
            Variable::KIND_VALUE,
            $this->context->constantFromInteger(1)
        );
        $newVal = $this->context->helper->binaryOp($arithOp, $current, $oneVar);
        if (JIT\PropertyHookDispatch::emitSetHookIfNeeded(
            $this->context,
            $read,
            $newVal,
            $this->context->jitEnclosingBlock,
            $this
        )) {
            if ($prefix) {
                $this->assignOperand($resultOp, $newVal, true);
            }

            return;
        }
        JIT\DynamicObjectReadonlyGuard::emitBeforePropertyStore(
            $this->context,
            $read,
            $this->context->jitEnclosingBlock
        );
        JIT\ReadonlyClassGuard::emitBeforePropertyStore(
            $this->context,
            $read,
            $this->context->jitEnclosingBlock,
            'modify',
            $this
        );
        if (JIT\AsymmetricVisibilityGuard::emitBeforePropertyStore(
            $this->context,
            $this,
            $read,
            $this->context->jitEnclosingBlock
        )) {
            return;
        }
        JIT\ReadonlyClassGuard::emitStoreUnlessPending(
            $this->context,
            function () use ($read, $newVal): void {
                $this->context->type->object->propertyStore(
                    $read->objectPropertySlot,
                    $newVal,
                    $read->objectPropertyType
                );
            }
        );
        if ($prefix) {
            $this->assignOperand($resultOp, $newVal, true);
        }
    }

    /**
     * ++/-- on magic-backed undeclared props: __get then __set or deprecated dynamic (#31992, #32016).
     */
    private function compileMagicPropertyIncDecOp(
        JIT\Variable $read,
        \PHPCfg\Operand $resultOp,
        bool $increment,
        bool $prefix,
        Block $block,
        OpCode $incDecOp
    ): void {
        $declaringClass = $read->objectPropertyClassName ?? 'object';
        $classId = $this->context->type->object->lookup($declaringClass);
        $receiverVar = new Variable(
            $this->context,
            Variable::TYPE_OBJECT,
            Variable::KIND_VALUE,
            $read->magicSetReceiver
        );
        // tryEmitMagicSet resolves the class from receiver->objectPropertyClassName (#31992 AOT).
        $receiverVar->objectPropertyClassName = $declaringClass;
        $propName = $read->magicSetName;
        $currentVal = JIT\MagicMethodDispatch::tryEmitMagicGet(
            $this->context,
            $read->magicSetReceiver,
            $declaringClass,
            $propName,
            $block
        );
        if (null === $currentVal) {
            $currentVar = new Variable(
                $this->context,
                Variable::TYPE_NULL,
                Variable::KIND_VALUE,
                $this->context->getTypeFromString('__value__*')->constNull()
            );
        } else {
            $currentVar = new Variable(
                $this->context,
                Variable::TYPE_VALUE,
                Variable::KIND_VALUE,
                $currentVal
            );
        }
        if (!$prefix) {
            $this->assignOperand($resultOp, $currentVar, true);
        }
        $arithOp = new OpCode($increment ? OpCode::TYPE_PLUS : OpCode::TYPE_MINUS);
        $oneVar = new Variable(
            $this->context,
            Variable::TYPE_NATIVE_LONG,
            Variable::KIND_VALUE,
            $this->context->constantFromInteger(1)
        );
        $newVal = $this->context->helper->binaryOp($arithOp, $currentVar, $oneVar);
        if (
            JIT\MagicMethodDispatch::hasInstanceMethod(
                $this->context->type->object,
                $classId,
                '__set'
            )
        ) {
            JIT\MagicMethodDispatch::tryEmitMagicSet(
                $this->context,
                $receiverVar,
                $propName,
                $newVal,
                $block
            );
        } else {
            $deprecationLine = null !== $incDecOp->sourceLocation && $incDecOp->sourceLocation->startLine > 0
                ? $incDecOp->sourceLocation->startLine
                : 0;
            JIT\DynamicPropertyDeprecationGuard::emitBeforeUndeclaredWrite(
                $this->context,
                $this->context->type->object,
                $classId,
                $declaringClass,
                $propName,
                $block->scriptPath(),
                $deprecationLine
            );
            if (null === JIT\BasicBlockHelper::tryGetInsertBlock($this->context)) {
                return;
            }
            $slot = $this->context->type->object->propertyFetch(
                $read->magicSetReceiver,
                $declaringClass,
                $propName
            );
            $writeVar = $this->context->getVariableFromOp($slot);
            JIT\ReadonlyClassGuard::emitStoreUnlessPending(
                $this->context,
                function () use ($writeVar, $newVal): void {
                    $this->context->type->object->propertyStore(
                        $writeVar->objectPropertySlot,
                        $newVal,
                        $writeVar->objectPropertyType
                    );
                }
            );
        }
        if ($prefix) {
            $this->assignOperand($resultOp, $newVal, true);
        }
    }

}
