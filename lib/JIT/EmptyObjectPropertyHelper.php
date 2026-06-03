<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPCfg\Operand\Temporary;
use PHPTypes\Type;
use PHPCompiler\ext\standard\boolval;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPLLVM\Value;

/**
 * empty($obj->prop) — typed declared slots read (throw when uninitialized);
 * __isset semantics otherwise (#4912, #3298, zend_object_handlers.c).
 */
final class EmptyObjectPropertyHelper
{
    public static function compile(
        Context $context,
        Variable $container,
        Variable $dim,
        ?Operand $dimOp,
        ?Operand $containerOp
    ): Value {
        $propName = self::literalStringKey($dimOp);
        if (
            null !== $propName
            && Variable::TYPE_OBJECT === $container->type
            && null !== $containerOp
            && null !== $containerOp->type
            && Type::TYPE_OBJECT === $containerOp->type->type
        ) {
            $class = $containerOp->type->userType ?? '';
            $object = $context->type->object;
            assert($object instanceof Object_);
            $resolved = $object->resolvePropertySlot($class, $propName);
            if (null !== $resolved && $object->propertySlotIsTypedValue($resolved[0], $resolved[1])) {
                $objPtr = Variable::KIND_VALUE === $container->kind
                    ? $container->value
                    : $context->builder->load($container->value);
                $fetched = $object->propertyFetch($objPtr, $class, $propName);
                TypedPropertyUninitGuard::emitBeforeRead($context, $fetched);
                $truthy = (new boolval())->call($context, $fetched);

                return $context->builder->not($truthy);
            }
        }

        $isset = IssetHelper::compile($context, $container, $dim, $dimOp, $containerOp);

        return $context->builder->not($isset);
    }

    private static function literalStringKey(?Operand $dimOp): ?string
    {
        if (null === $dimOp) {
            return null;
        }
        while ($dimOp instanceof Temporary) {
            if (null === $dimOp->original) {
                return null;
            }
            $dimOp = $dimOp->original;
        }
        if (!$dimOp instanceof Literal) {
            return null;
        }
        if (null === $dimOp->type || Type::TYPE_STRING !== $dimOp->type->type) {
            return null;
        }

        return is_string($dimOp->value) ? $dimOp->value : null;
    }
}
