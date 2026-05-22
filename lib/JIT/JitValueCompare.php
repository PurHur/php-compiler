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
            case Variable::TYPE_NULL:
                $nullTag = $i8->constInt(Variable::TYPE_NULL, false);

                return $context->builder->icmp(Builder::INT_EQ, $typeByte, $nullTag);
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

        $nullTag = $i8->constInt(Variable::TYPE_NULL, false);
        $bothNull = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $leftType, $nullTag),
            $context->builder->icmp(Builder::INT_EQ, $rightType, $nullTag)
        );

        $entry = $context->builder->getInsertBlock();
        $i1 = $context->getTypeFromString('int1');
        $trueVal = $i1->constInt(1, false);
        $mergeBlock = BasicBlockHelper::append($context, 'identical_value_merge');
        $typedBlock = BasicBlockHelper::append($context, 'identical_value_typed');

        $context->builder->branchIf($bothNull, $mergeBlock, $typedBlock);

        $context->builder->positionAtEnd($typedBlock);
        [$typedMatch, $typedDone] = self::identicalValueToValueTyped(
            $context,
            $leftPtr,
            $rightPtr,
            $leftType,
            $rightType,
            $mergeBlock
        );

        $context->builder->positionAtEnd($mergeBlock);
        $matchPhi = $context->builder->phi($i1);
        $matchPhi->addIncoming($trueVal, $entry);
        $matchPhi->addIncoming($typedMatch, $typedDone);

        return $context->builder->and($sameType, $matchPhi);
    }

    /**
     * Compare two boxed values of the same non-null type tag (caller skips dual-null).
     */
    private static function identicalValueToValueTyped(
        Context $context,
        Value $leftPtr,
        Value $rightPtr,
        Value $leftType,
        Value $rightType,
        \PHPLLVM\BasicBlock $exitBlock
    ): array {
        $i8 = $context->getTypeFromString('int8');
        $falseVal = $context->getTypeFromString('int1')->constInt(0, false);

        $stringTag = $i8->constInt(Variable::TYPE_STRING, false);
        $bothString = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $leftType, $stringTag),
            $context->builder->icmp(Builder::INT_EQ, $rightType, $stringTag)
        );

        $longTag = $i8->constInt(Variable::TYPE_NATIVE_LONG, false);
        $bothLong = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $leftType, $longTag),
            $context->builder->icmp(Builder::INT_EQ, $rightType, $longTag)
        );

        $entry = $context->builder->getInsertBlock();
        $i1 = $context->getTypeFromString('int1');
        $stringBlock = BasicBlockHelper::append($context, 'identical_value_string');
        $longCheckBlock = BasicBlockHelper::append($context, 'identical_value_long_check');
        $longBlock = BasicBlockHelper::append($context, 'identical_value_long');
        $typedFalseBlock = BasicBlockHelper::append($context, 'identical_value_typed_false');
        $doneBlock = BasicBlockHelper::append($context, 'identical_value_typed_done');

        $context->builder->branchIf($bothString, $stringBlock, $longCheckBlock);

        $context->builder->positionAtEnd($stringBlock);
        $leftStr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $leftPtr
        );
        $rightStr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $rightPtr
        );
        $stringMap = $context->structFieldMap['__string__'];
        $cmp = $context->builder->call(
            $context->lookupFunction('strcmp'),
            $context->builder->structGep($leftStr, $stringMap['value']),
            $context->builder->structGep($rightStr, $stringMap['value'])
        );
        $stringsMatch = $context->builder->icmp(Builder::INT_EQ, $cmp, $cmp->typeOf()->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($longCheckBlock);
        $context->builder->branchIf($bothLong, $longBlock, $typedFalseBlock);

        $context->builder->positionAtEnd($longBlock);
        $leftLong = $context->builder->call($context->lookupFunction('__value__readLong'), $leftPtr);
        $rightLong = $context->builder->call($context->lookupFunction('__value__readLong'), $rightPtr);
        $longMatch = $context->builder->icmp(Builder::INT_EQ, $leftLong, $rightLong);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($typedFalseBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($stringsMatch, $stringBlock);
        $phi->addIncoming($longMatch, $longBlock);
        $phi->addIncoming($falseVal, $typedFalseBlock);
        $context->builder->branch($exitBlock);

        return [$phi, $doneBlock];
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
