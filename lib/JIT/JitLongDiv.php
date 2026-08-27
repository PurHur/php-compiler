<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value as LlvmValue;

/**
 * JIT long / long matching php-src div_function (#35337 / zend_operators.c).
 *
 * Exact integer quotients stay IS_LONG; non-exact promote to double. INT_MIN / -1 → double.
 */
final class JitLongDiv
{
    /**
     * Compile-time fold for native-long `/` when both operands are constant.
     */
    public static function tryFoldBinary(
        Context $context,
        Variable $left,
        Variable $right
    ): ?Variable {
        $a = self::extractConstantLong($context, $left);
        $b = self::extractConstantLong($context, $right);
        if (null === $a || null === $b) {
            return null;
        }
        $result = self::foldNativeInts($a, $b);
        if (\is_int($result)) {
            return new Variable(
                $context,
                Variable::TYPE_NATIVE_LONG,
                Variable::KIND_VALUE,
                $context->constantFromInteger($result, 'int64')
            );
        }

        return new Variable(
            $context,
            Variable::TYPE_NATIVE_DOUBLE,
            Variable::KIND_VALUE,
            $context->constantFromFloat((float) $result, 'double')
        );
    }

    /**
     * Native long ⊙ native long `/` → boxed __value__ (long or double inside).
     */
    public static function binaryNativeLong(
        Context $context,
        LlvmValue $left,
        LlvmValue $right
    ): Variable {
        $slot = JitValueBox::alloc($context);
        $slotPtr = JitValueBox::pointer($context, $slot);
        self::writeBoxedBinary($context, $left, $right, $slotPtr);

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VALUE, $slotPtr);
    }

    /**
     * Boxed long ⊙ boxed long `/` — write long or double into an existing value slot.
     */
    public static function writeBoxedBinary(
        Context $context,
        LlvmValue $left,
        LlvmValue $right,
        LlvmValue $slotPtr
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'longdiv_boxed_cont');
        $i64 = $context->getTypeFromString('int64');
        $f64 = $context->getTypeFromString('double');
        $a = $context->builder->intCast($left, $i64);
        $b = $context->builder->intCast($right, $i64);
        JitNumericDivisionGuard::emitZeroLongDivisorGuard($context, $b, 'Division by zero');

        $rem = $context->builder->signedRem($a, $b);
        $isExact = $context->builder->icmp(Builder::INT_EQ, $rem, $i64->constInt(0, false));

        $exactBlock = BasicBlockHelper::append($context, 'longdiv_box_exact');
        $floatBlock = BasicBlockHelper::append($context, 'longdiv_box_float');
        $doneBlock = BasicBlockHelper::append($context, 'longdiv_box_done');
        $context->builder->branchIf($isExact, $exactBlock, $floatBlock);

        $context->builder->positionAtEnd($exactBlock);
        $intMin = $i64->constInt(\PHP_INT_MIN, true);
        $negOne = $i64->constInt(-1, true);
        $isIntMinNegOne = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $a, $intMin),
            $context->builder->icmp(Builder::INT_EQ, $b, $negOne)
        );
        $exactLongBlock = BasicBlockHelper::append($context, 'longdiv_box_exact_long');
        $exactDoubleBlock = BasicBlockHelper::append($context, 'longdiv_box_exact_double');
        $context->builder->branchIf($isIntMinNegOne, $exactDoubleBlock, $exactLongBlock);

        $context->builder->positionAtEnd($exactLongBlock);
        $longResult = $context->builder->signedDiv($a, $b);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $slotPtr,
            $longResult
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($exactDoubleBlock);
        $intMinDouble = $context->builder->fdiv(
            $context->builder->siToFp($a, $f64),
            $context->builder->siToFp($b, $f64)
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $slotPtr,
            $intMinDouble
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($floatBlock);
        $floatResult = $context->builder->fdiv(
            $context->builder->siToFp($a, $f64),
            $context->builder->siToFp($b, $f64)
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $slotPtr,
            $floatResult
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        JitValueBox::publishAfterWrite($context, $slotPtr);
    }

    /**
     * @return int|float
     */
    private static function foldNativeInts(int $a, int $b): int|float
    {
        if (0 === $b) {
            throw new \DivisionByZeroError('Division by zero');
        }
        if (0 === $a % $b) {
            if (\PHP_INT_MIN === $a && -1 === $b) {
                return (float) ($a / $b);
            }

            return intdiv($a, $b);
        }

        return $a / $b;
    }

    private static function extractConstantLong(Context $context, Variable $var): ?int
    {
        if (Variable::KIND_VARIABLE === $var->kind) {
            return null;
        }
        if (JitValueBox::isValueOperand($var)) {
            return null;
        }
        if (null !== $var->compileTimeLong) {
            return $var->compileTimeLong;
        }
        if (Variable::KIND_VALUE !== $var->kind
            || null === $var->value
            || null === $context->llvm->lib->LLVMIsAConstantInt($var->value->value)
        ) {
            return null;
        }

        return (int) $context->llvm->lib->LLVMConstIntGetSExtValue($var->value->value);
    }
}
