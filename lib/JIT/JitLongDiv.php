<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value as LlvmValue;

/**
 * JIT long / long matching php-src div_function (#35337 / zend_operators.c).
 *
 * Exact integer quotients stay {@see Variable::TYPE_NATIVE_LONG} on the hot path
 * (no {@code __value__} box); non-exact and INT_MIN/−1 promote via a cold f64
 * slot and {@see JitLongArithOverflow::materializeOverflowableNativeLong} (#36386).
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
     * Native long ⊙ native long `/`.
     *
     * Hot path (exact quotient, not INT_MIN/−1) stays {@see Variable::TYPE_NATIVE_LONG}
     * with no {@see JitValueBox::alloc} — peer of {@see JitLongArithOverflow::binaryNativeLong}
     * (#36386). Non-exact / INT_MIN÷−1 stores f64 into an entry alloca; the {@code __value__}
     * box is created only in {@see JitLongArithOverflow::materializeOverflowableNativeLong}.
     *
     * @see php-src Zend/zend_operators.c div_function
     */
    public static function binaryNativeLong(
        Context $context,
        LlvmValue $left,
        LlvmValue $right
    ): Variable {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'longdiv_native_cont');
        $i64 = $context->getTypeFromString('int64');
        $f64 = $context->getTypeFromString('double');
        $a = $context->builder->intCast($left, $i64);
        $b = $context->builder->intCast($right, $i64);
        JitNumericDivisionGuard::emitZeroLongDivisorGuard($context, $b, 'Division by zero');

        $rem = $context->builder->signedRem($a, $b);
        $isExact = $context->builder->icmp(
            \PHPLLVM\Builder::INT_EQ,
            $rem,
            $i64->constInt(0, false)
        );
        $intMin = $i64->constInt(\PHP_INT_MIN, true);
        $negOne = $i64->constInt(-1, true);
        $isIntMinNegOne = $context->builder->and(
            $context->builder->icmp(\PHPLLVM\Builder::INT_EQ, $a, $intMin),
            $context->builder->icmp(\PHPLLVM\Builder::INT_EQ, $b, $negOne)
        );
        // Promote when non-exact OR INT_MIN/−1 (exact rem but not representable as long).
        $promote = $context->builder->or(
            $context->builder->not($isExact),
            $isIntMinNegOne
        );

        // f64 only — no entryAllocaValueBox / TYPE_NULL init on the hot path (#36386).
        $doubleSlot = BasicBlockHelper::entryAlloca($context, $f64);

        $longBlock = BasicBlockHelper::append($context, 'longdiv_native_long');
        $floatBlock = BasicBlockHelper::append($context, 'longdiv_native_float');
        $doneBlock = BasicBlockHelper::append($context, 'longdiv_native_done');
        $context->builder->branchIf($promote, $floatBlock, $longBlock);

        $context->builder->positionAtEnd($longBlock);
        $longResult = $context->builder->signedDiv($a, $b);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($floatBlock);
        $floatResult = $context->builder->fdiv(
            $context->builder->siToFp($a, $f64),
            $context->builder->siToFp($b, $f64)
        );
        $context->builder->store($floatResult, $doubleSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        // Dummy 0 on the promote arm — consumers must materialize when the flag is set.
        $mergedLong = $context->builder->phi($i64);
        $mergedLong->addIncoming($longResult, $longBlock);
        $mergedLong->addIncoming($i64->constInt(0, false), $floatBlock);

        $okVar = new Variable($context, Variable::TYPE_NATIVE_LONG, Variable::KIND_VALUE, $mergedLong);
        $okVar->longArithOverflowFlag = $promote;
        $okVar->longArithOverflowDoubleSlot = $doubleSlot;

        return $okVar;
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
