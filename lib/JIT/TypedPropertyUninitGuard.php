<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;

/**
 * Uninitialized typed property read guards for JIT/AOT (#4569, zend_object_handlers.c).
 */
final class TypedPropertyUninitGuard
{
    public static function emitBeforeRead(Context $context, Variable $var): void
    {
        if (Variable::TYPE_VALUE !== $var->type) {
            return;
        }
        if (null === $var->objectPropertyClassName || null === $var->objectPropertyName) {
            return;
        }
        $object = $context->type->object;
        $classId = $object->lookup($var->objectPropertyClassName);
        $slotIndex = $object->propertySlotIndex($classId, $var->objectPropertyName);
        if (null === $slotIndex || $object->propertySlotHasCompileTimeDefault($classId, $slotIndex)) {
            return;
        }
        $valuePtr = self::valuePtrFromVariable($context, $var);
        if (null === $valuePtr) {
            return;
        }
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $isUndef = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_UNDEFINED, false)
        );
        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof \PHPLLVM\Value\Function_);
        $raiseBlock = $fn->appendBasicBlock('typed_prop_uninit_raise');
        $continueBlock = $fn->appendBasicBlock('typed_prop_uninit_ok');
        $context->builder->branchIf($isUndef, $raiseBlock, $continueBlock);
        $context->builder->positionAtEnd($raiseBlock);
        ErrorRaise::emitRaise(
            $context,
            sprintf(
                'Typed property %s::$%s must not be accessed before initialization',
                $var->objectPropertyClassName,
                $var->objectPropertyName
            )
        );
        $context->builder->branch($continueBlock);
        $context->builder->positionAtEnd($continueBlock);
    }

    private static function valuePtrFromVariable(Context $context, Variable $var): ?\PHPLLVM\Value
    {
        if (null !== $var->valueBoxAliasPtr) {
            return JitValueBox::normalizeValuePtr($context, $var->valueBoxAliasPtr);
        }
        if (Variable::KIND_VARIABLE === $var->kind) {
            return JitValueBox::pointer($context, $var->value);
        }

        return null;
    }
}
