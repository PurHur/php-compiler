<?php

declare(strict_types=1);

/**
 * Strict equality between boxed {@see __value__} and native JIT operands.
 */

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitValueCompare
{
    public static function identicalToNative(
        Context $context,
        Variable $boxed,
        Variable $native
    ): Value {
        if (Variable::TYPE_VALUE !== $boxed->type) {
            throw new \LogicException('Expected boxed __value__ operand');
        }

        $valuePtr = Variable::KIND_VARIABLE === $boxed->kind
            ? $boxed->value
            : $context->helper->loadValue($boxed);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $falseVal = $context->getTypeFromString('int1')->constInt(0, false);

        switch ($native->type) {
            case Variable::TYPE_NATIVE_BOOL:
                $nativeBool = $context->helper->loadValue($native);
                $sentinel = $context->getTypeFromString('int64')->constInt(
                    \PHPCompiler\ext\standard\JitStrpos::NOT_FOUND,
                    false
                );
                $longType = $i8->constInt(Variable::TYPE_NATIVE_LONG, false);
                $boolType = $i8->constInt(Variable::TYPE_NATIVE_BOOL, false);
                $isLong = $context->builder->icmp(Builder::INT_EQ, $typeByte, $longType);
                $isBool = $context->builder->icmp(Builder::INT_EQ, $typeByte, $boolType);
                $storedLong = $context->builder->call(
                    $context->lookupFunction('__value__readLong'),
                    $valuePtr
                );
                $isNotFound = $context->builder->icmp(Builder::INT_EQ, $storedLong, $sentinel);
                $isFalse = $context->builder->icmp(
                    Builder::INT_EQ,
                    $nativeBool,
                    $nativeBool->typeOf()->constInt(0, false)
                );
                $strposMatch = $context->builder->and($isLong, $context->builder->and($isNotFound, $isFalse));
                $storedBool = $context->builder->icmp(
                    Builder::INT_NE,
                    $storedLong,
                    $storedLong->typeOf()->constInt(0, false)
                );
                $boolMatch = $context->builder->and(
                    $isBool,
                    $context->builder->icmp(Builder::INT_EQ, $storedBool, $nativeBool)
                );

                return $context->builder->or($strposMatch, $boolMatch);
            case Variable::TYPE_NATIVE_LONG:
                $expectedType = $i8->constInt(Variable::TYPE_NATIVE_LONG, false);
                $sameType = $context->builder->icmp(Builder::INT_EQ, $typeByte, $expectedType);
                $stored = $context->builder->call(
                    $context->lookupFunction('__value__readLong'),
                    $valuePtr
                );
                $nativeLong = $context->helper->loadValue($native);

                return $context->builder->and(
                    $sameType,
                    $context->builder->icmp(Builder::INT_EQ, $stored, $nativeLong)
                );
            default:
                return $falseVal;
        }
    }

    public static function identicalNativeToValue(
        Context $context,
        Variable $native,
        Variable $boxed
    ): Value {
        return self::identicalToNative($context, $boxed, $native);
    }
}
