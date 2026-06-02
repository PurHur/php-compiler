<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;

/**
 * Uninitialized typed property read guards for JIT/AOT (#4569, #4614, zend_object_handlers.c).
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
        assert($object instanceof Object_);
        $resolved = $object->resolvePropertySlot($var->objectPropertyClassName, $var->objectPropertyName);
        if (null === $resolved) {
            return;
        }
        [$classId, $slotIndex] = $resolved;
        if ($object->propertySlotHasCompileTimeDefault($classId, $slotIndex)) {
            return;
        }
        $valuePtr = self::valuePtrFromVariable($context, $var);
        if (null === $valuePtr) {
            return;
        }
        $declaringClass = $object->classNameForId($classId);

        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof \PHPLLVM\Value\Function_);
        $entry = $context->builder->getInsertBlock();
        if (null === $entry || null !== $entry->getTerminator()) {
            return;
        }

        $checkBlock = $fn->appendBasicBlock('typed_prop_uninit_check');
        $okBlock = $fn->appendBasicBlock('typed_prop_uninit_ok');
        $exitBlock = $fn->appendBasicBlock('typed_prop_uninit_exit');
        $raiseBlock = $fn->appendBasicBlock('typed_prop_uninit_raise');

        $context->builder->positionAtEnd($entry);
        $context->builder->branch($checkBlock);

        $context->builder->positionAtEnd($checkBlock);
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
        $context->builder->branchIf($isUndef, $raiseBlock, $okBlock);

        $context->builder->positionAtEnd($raiseBlock);
        ErrorRaise::emitRaise(
            $context,
            sprintf(
                'Typed property %s::$%s must not be accessed before initialization',
                $declaringClass,
                $var->objectPropertyName
            )
        );
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($okBlock);
        $context->builder->branch($exitBlock);
        $context->builder->positionAtEnd($exitBlock);
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
