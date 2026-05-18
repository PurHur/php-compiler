<?php

declare(strict_types=1);

/**
 * JIT lowering for isset() (subset of PHP semantics for static compilation).
 */

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value;

final class IssetHelper
{
    public static function compile(Context $context, Variable $container, ?Variable $dim): Value
    {
        if (null === $dim) {
            return self::compileVariableIsSet($context, $container);
        }

        return self::compileOffsetIsSet($context, $container, $dim);
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
                $null = $loaded->typeOf()->constPointerNull();

                return $context->builder->icmp(Builder::INT_NE, $loaded, $null);
            case Variable::TYPE_VALUE:
                $typeField = $context->structFieldMap['__value__']['type'];
                $typeByte = $context->builder->load(
                    $context->builder->structGep($loaded, $typeField)
                );
                $nullType = $context->getTypeFromString('int8')->constInt(0, false);

                return $context->builder->icmp(Builder::INT_NE, $typeByte, $nullType);
            case Variable::TYPE_HASHTABLE:
                $null = $loaded->typeOf()->constPointerNull();

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

    private static function compileOffsetIsSet(Context $context, Variable $container, Variable $dim): Value
    {
        if ($container->type === Variable::TYPE_STRING) {
            return self::compileStringOffsetIsSet($context, $container, $dim);
        }
        if ($container->type & Variable::IS_NATIVE_ARRAY) {
            return self::compileNativeArrayOffsetIsSet($context, $container, $dim);
        }
        if ($container->type === Variable::TYPE_HASHTABLE) {
            throw new \LogicException(
                'isset() on HashTable array offsets is not implemented for JIT in this compiler build'
            );
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
