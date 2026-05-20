<?php

declare(strict_types=1);

/**
 * JIT lowering for isset() (subset of PHP semantics for static compilation).
 */

namespace PHPCompiler\JIT;

use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPCfg\Operand\Temporary;
use PHPTypes\Type;
use PHPCompiler\Web\Superglobals;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class IssetHelper
{
    public static function compile(
        Context $context,
        Variable $container,
        ?Variable $dim,
        ?Operand $dimOp = null,
        ?Operand $containerOp = null
    ): Value {
        if (null === $dim) {
            return self::compileVariableIsSet($context, $container);
        }

        return self::compileOffsetIsSet($context, $container, $dim, $dimOp, $containerOp);
    }

    private static function superglobalName(Variable $container, ?Operand $containerOp): ?string
    {
        if (null !== $container->superglobalName) {
            return $container->superglobalName;
        }
        $name = OperandName::resolve($containerOp);
        if (null !== $name && Superglobals::isSuperglobalName($name)) {
            return $name;
        }

        return null;
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

    private static function compileVariableIsSet(Context $context, Variable $var): Value
    {
        $loaded = $context->helper->loadValue($var);
        $i1 = $context->getTypeFromString('int1');

        switch ($var->type) {
            case Variable::TYPE_NULL:
                return $i1->constInt(0, false);
            case Variable::TYPE_NATIVE_LONG:
            case Variable::TYPE_NATIVE_BOOL:
            case Variable::TYPE_NATIVE_DOUBLE:
                return $i1->constInt(1, false);
            case Variable::TYPE_STRING:
                $null = $context->getTypeFromString('__string__*')->constNull();

                return $context->builder->icmp(Builder::INT_NE, $loaded, $null);
            case Variable::TYPE_VALUE:
                $typeField = $context->structFieldMap['__value__']['type'];
                $typeByte = $context->builder->load(
                    $context->builder->structGep($loaded, $typeField)
                );
                $nullType = $context->getTypeFromString('int8')->constInt(0, false);

                return $context->builder->icmp(Builder::INT_NE, $typeByte, $nullType);
            case Variable::TYPE_HASHTABLE:
                $null = $context->getTypeFromString('__hashtable__*')->constNull();

                return $context->builder->icmp(Builder::INT_NE, $loaded, $null);
            default:
                if ($var->type & Variable::IS_NATIVE_ARRAY) {
                    return $i1->constInt(1, false);
                }
                throw new \LogicException(
                    'isset() on variables of type '
                    .Variable::getStringType($var->type)
                    .' is not implemented for JIT in this compiler build'
                );
        }
    }

    private static function compileOffsetIsSet(
        Context $context,
        Variable $container,
        Variable $dim,
        ?Operand $dimOp,
        ?Operand $containerOp
    ): Value {
        if ($container->type === Variable::TYPE_STRING) {
            return self::compileStringOffsetIsSet($context, $container, $dim);
        }
        if ($container->type & Variable::IS_NATIVE_ARRAY) {
            return self::compileNativeArrayOffsetIsSet($context, $container, $dim);
        }
        if ($container->type === Variable::TYPE_HASHTABLE) {
            return self::compileHashTableOffsetIsSet($context, $container, $dim, $dimOp, $containerOp);
        }

        throw new \LogicException(
            'isset() with array offset is not supported for this container type in JIT mode'
        );
    }

    private static function compileStringOffsetIsSet(Context $context, Variable $container, Variable $dim): Value
    {
        if (Variable::TYPE_NATIVE_LONG !== $dim->type) {
            throw new \LogicException('isset() on string offsets only supports integer indices in this compiler build');
        }
        $str = $context->helper->loadValue($container);
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load(
            $context->builder->structGep($str, $map['length'])
        );
        $index = $context->helper->loadValue($dim);
        $i32 = $context->getTypeFromString('int32');
        $inRange = $context->builder->icmp(Builder::INT_SLT, $index, $len);
        $nonNeg = $context->builder->icmp(Builder::INT_SGE, $index, $i32->constInt(0, false));

        return $context->builder->and($inRange, $nonNeg);
    }

    private static function compileHashTableOffsetIsSet(
        Context $context,
        Variable $container,
        Variable $dim,
        ?Operand $dimOp,
        ?Operand $containerOp
    ): Value {
        $superglobalName = self::superglobalName($container, $containerOp);
        if (null !== $superglobalName) {
            $key = $dim->compileTimeString ?? self::literalStringKey($dimOp);
            if (null !== $key) {
                $known = SuperglobalInit::compileTimeOffsetIsSet(
                    $context,
                    $superglobalName,
                    $key
                );

                return $context->getTypeFromString('int1')->constInt($known ? 1 : 0, false);
            }
            $ht = $context->helper->loadValue($container);
            if (Variable::TYPE_STRING === $dim->type) {
                return $context->builder->call(
                    $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
                    $ht,
                    $context->helper->loadValue($dim)
                );
            }
            if (Variable::TYPE_NATIVE_LONG === $dim->type) {
                $index = $context->builder->truncOrBitCast(
                    $context->helper->loadValue($dim),
                    $context->getTypeFromString('size_t')
                );

                return $context->builder->call(
                    $context->lookupFunction('__hashtable__offsetIsSet'),
                    $ht,
                    $index
                );
            }
            throw new \LogicException(
                'isset() on superglobals only supports string or integer keys in this compiler build'
            );
        }

        $ht = $context->helper->loadValue($container);
        if (Variable::TYPE_STRING === $dim->type) {
            return $context->builder->call(
                $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
                $ht,
                $context->helper->loadValue($dim)
            );
        }
        if (Variable::TYPE_NATIVE_LONG !== $dim->type) {
            throw new \LogicException('isset() on HashTable arrays only supports integer or string indices in this compiler build');
        }
        $index = $context->builder->truncOrBitCast(
            $context->helper->loadValue($dim),
            $context->getTypeFromString('size_t')
        );

        return $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $index
        );
    }

    private static function compileNativeArrayOffsetIsSet(Context $context, Variable $container, Variable $dim): Value
    {
        if (Variable::TYPE_NATIVE_LONG !== $dim->type) {
            throw new \LogicException('isset() on native arrays only supports integer indices in this compiler build');
        }
        $index = $context->helper->loadValue($dim);
        $size = $context->constantFromInteger($container->nextFreeElement, 'int32');
        $i32 = $context->getTypeFromString('int32');
        $inRange = $context->builder->icmp(Builder::INT_SLT, $index, $size);
        $nonNeg = $context->builder->icmp(Builder::INT_SGE, $index, $i32->constInt(0, false));

        return $context->builder->and($inRange, $nonNeg);
    }
}
