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
                $nullTag = $i8->constInt(Variable::TYPE_NULL, false);
                $isNull = $context->builder->icmp(Builder::INT_EQ, $typeByte, $nullTag);
                $boolTag = $i8->constInt(Variable::TYPE_NATIVE_BOOL, false);
                $isBool = $context->builder->icmp(Builder::INT_EQ, $typeByte, $boolTag);
                $stored = $context->builder->call(
                    $context->lookupFunction('__value__readLong'),
                    $valuePtr
                );
                $nativeBool = $context->helper->loadValue($native);
                $matches = $context->builder->icmp(Builder::INT_EQ, $stored, $nativeBool);

                return $context->builder->select(
                    $isNull,
                    $falseVal,
                    $context->builder->select($isBool, $matches, $falseVal)
                );
            case Variable::TYPE_NATIVE_LONG:
                $expectedType = $i8->constInt(Variable::TYPE_NATIVE_LONG, false);
                $sameType = $context->builder->icmp(Builder::INT_EQ, $typeByte, $expectedType);
                $stored = $context->builder->call(
                    $context->lookupFunction('__value__readLong'),
                    $valuePtr
                );
                $nativeLong = $context->helper->loadValue($native);
                $matches = $context->builder->icmp(Builder::INT_EQ, $stored, $nativeLong);

                return $context->builder->select($sameType, $matches, $falseVal);
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

    public static function notIdenticalToNative(
        Context $context,
        Variable $boxed,
        Variable $native
    ): Value {
        $same = self::identicalToNative($context, $boxed, $native);
        $i1 = $context->getTypeFromString('int1');

        return $context->builder->xor($same, $i1->constInt(1, false));
    }

    public static function notIdenticalNativeToValue(
        Context $context,
        Variable $native,
        Variable $boxed
    ): Value {
        return self::notIdenticalToNative($context, $boxed, $native);
    }

    public static function identicalValueToValue(
        Context $context,
        Variable $left,
        Variable $right
    ): Value {
        if (Variable::TYPE_VALUE !== $left->type || Variable::TYPE_VALUE !== $right->type) {
            throw new \LogicException('Expected two boxed __value__ operands');
        }

        $leftPtr = Variable::KIND_VARIABLE === $left->kind
            ? $left->value
            : $context->helper->loadValue($left);
        $rightPtr = Variable::KIND_VARIABLE === $right->kind
            ? $right->value
            : $context->helper->loadValue($right);
        $map = $context->structFieldMap['__value__'];
        $leftType = $context->builder->load($context->builder->structGep($leftPtr, $map['type']));
        $rightType = $context->builder->load($context->builder->structGep($rightPtr, $map['type']));
        $i8 = $context->getTypeFromString('int8');
        $falseVal = $context->getTypeFromString('int1')->constInt(0, false);
        $sameType = $context->builder->icmp(Builder::INT_EQ, $leftType, $rightType);

        $stringTag = $i8->constInt(Variable::TYPE_STRING, false);
        $bothString = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $leftType, $stringTag),
            $context->builder->icmp(Builder::INT_EQ, $rightType, $stringTag)
        );
        $leftStr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $leftPtr
        );
        $rightStr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $rightPtr
        );
        $cmp = $context->builder->call($context->lookupFunction('strcmp'), $leftStr, $rightStr);
        $stringsMatch = $context->builder->icmp(Builder::INT_EQ, $cmp, $cmp->typeOf()->constInt(0, false));
        $stringIdentical = $context->builder->and($bothString, $stringsMatch);

        $longTag = $i8->constInt(Variable::TYPE_NATIVE_LONG, false);
        $bothLong = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $leftType, $longTag),
            $context->builder->icmp(Builder::INT_EQ, $rightType, $longTag)
        );
        $leftLong = $context->builder->call($context->lookupFunction('__value__readLong'), $leftPtr);
        $rightLong = $context->builder->call($context->lookupFunction('__value__readLong'), $rightPtr);
        $longIdentical = $context->builder->and(
            $bothLong,
            $context->builder->icmp(Builder::INT_EQ, $leftLong, $rightLong)
        );

        $nullTag = $i8->constInt(Variable::TYPE_NULL, false);
        $bothNull = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $leftType, $nullTag),
            $context->builder->icmp(Builder::INT_EQ, $rightType, $nullTag)
        );

        $typedMatch = $context->builder->or(
            $stringIdentical,
            $context->builder->or($longIdentical, $bothNull)
        );

        return $context->builder->select($sameType, $typedMatch, $falseVal);
    }

    public static function notIdenticalValueToValue(
        Context $context,
        Variable $left,
        Variable $right
    ): Value {
        $same = self::identicalValueToValue($context, $left, $right);
        $i1 = $context->getTypeFromString('int1');

        return $context->builder->xor($same, $i1->constInt(1, false));
    }
}
