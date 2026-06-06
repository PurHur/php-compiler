<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPCfg\Operand\Temporary;
use PHPTypes\Type;
use PHPCompiler\ext\standard\boolval;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * empty($obj->prop) — uninitialized typed slots are empty without read (#6787, zend_object_handlers.c);
 * __isset semantics otherwise (#3298).
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

                return self::compileEmptyFromFetchedValue($context, $fetched);
            }
        }

        $isset = IssetHelper::compile($context, $container, $dim, $dimOp, $containerOp);

        return $context->builder->not($isset);
    }

    /**
     * empty(value): true when uninitialized typed slot or value is falsy (#6787).
     */
    public static function compileEmptyFromValue(Context $context, Variable $var): Value
    {
        return self::compileEmptyFromFetchedValue($context, $var);
    }

    /**
     * empty(slot): true when uninitialized typed or value is falsy — no read guard (#6787).
     */
    private static function compileEmptyFromFetchedValue(Context $context, Variable $fetched): Value
    {
        if (Variable::TYPE_VALUE !== $fetched->type) {
            $truthy = (new boolval())->call($context, $fetched);

            return $context->builder->not($truthy);
        }
        $valuePtr = $fetched->valueBoxAliasPtr ?? null;
        if (null === $valuePtr && Variable::KIND_VARIABLE === $fetched->kind) {
            $valuePtr = JitValueBox::pointer($context, $fetched->value);
        }
        if (null === $valuePtr) {
            $truthy = (new boolval())->call($context, $fetched);

            return $context->builder->not($truthy);
        }
        $valuePtr = JitValueBox::normalizeValuePtr($context, $valuePtr);
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
        $truthy = (new boolval())->call($context, $fetched);
        $valueEmpty = $context->builder->not($truthy);

        return $context->builder->select(
            $isUndef,
            $context->constantFromBool(true),
            $valueEmpty
        );
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
