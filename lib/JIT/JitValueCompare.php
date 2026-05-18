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
        if (Variable::KIND_VALUE !== $boxed->kind || Variable::TYPE_VALUE !== $boxed->type) {
            throw new \LogicException('Expected boxed __value__ operand');
        }

        $valuePtr = $context->helper->loadValue($boxed);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $falseVal = $context->getTypeFromString('int1')->constInt(0, false);

        switch ($native->type) {
            case Variable::TYPE_NATIVE_BOOL:
                $expectedType = $i8->constInt(Variable::TYPE_NATIVE_BOOL, false);
                $sameType = $context->builder->icmp(Builder::INT_EQ, $typeByte, $expectedType);
                $boolBlock = BasicBlockHelper::append($context, 'ident_value_bool');
                $failBlock = BasicBlockHelper::append($context, 'ident_value_fail');
                $mergeBlock = BasicBlockHelper::append($context, 'ident_value_merge');
                $context->builder->branchIf($sameType, $boolBlock, $failBlock);
                $context->builder->positionAtEnd($boolBlock);
                $stored = $context->builder->call(
                    $context->lookupFunction('__value__readLong'),
                    $valuePtr
                );
                $storedBool = $context->builder->icmp(
                    Builder::INT_NE,
                    $stored,
                    $stored->typeOf()->constInt(0, false)
                );
                $nativeBool = $context->helper->loadValue($native);
                $match = $context->builder->icmp(Builder::INT_EQ, $storedBool, $nativeBool);
                $context->builder->branch($mergeBlock);
                $context->builder->positionAtEnd($failBlock);
                $context->builder->branch($mergeBlock);
                $context->builder->positionAtEnd($mergeBlock);
                $phi = $context->builder->phi($match->typeOf());
                $phi->addIncoming($match, $boolBlock);
                $phi->addIncoming($falseVal, $failBlock);

                return $phi;
            case Variable::TYPE_NATIVE_LONG:
                $expectedType = $i8->constInt(Variable::TYPE_NATIVE_LONG, false);
                $sameType = $context->builder->icmp(Builder::INT_EQ, $typeByte, $expectedType);
                $longBlock = BasicBlockHelper::append($context, 'ident_value_long');
                $failBlock = BasicBlockHelper::append($context, 'ident_value_fail_long');
                $mergeBlock = BasicBlockHelper::append($context, 'ident_value_merge_long');
                $context->builder->branchIf($sameType, $longBlock, $failBlock);
                $context->builder->positionAtEnd($longBlock);
                $stored = $context->builder->call(
                    $context->lookupFunction('__value__readLong'),
                    $valuePtr
                );
                $nativeLong = $context->helper->loadValue($native);
                $match = $context->builder->icmp(Builder::INT_EQ, $stored, $nativeLong);
                $context->builder->branch($mergeBlock);
                $context->builder->positionAtEnd($failBlock);
                $context->builder->branch($mergeBlock);
                $context->builder->positionAtEnd($mergeBlock);
                $phi = $context->builder->phi($match->typeOf());
                $phi->addIncoming($match, $longBlock);
                $phi->addIncoming($falseVal, $failBlock);

                return $phi;
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
