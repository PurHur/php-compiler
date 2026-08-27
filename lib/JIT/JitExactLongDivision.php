<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value as LlvmValue;

/**
 * JIT long/long `/` with Zend exact-quotient → int promotion (#35337).
 *
 * @see php-src Zend/zend_operators.c div_function — IS_LONG/IS_LONG:
 *      op1 % op2 == 0 → ZVAL_LONG, else ZVAL_DOUBLE; PHP_INT_MIN / -1 → double
 */
final class JitExactLongDivision
{
    /**
     * Compile-time fold for native-long `/` operands.
     */
    public static function tryFoldBinary(Context $context, Variable $left, Variable $right): ?Variable
    {
        $a = self::extractConstantLong($context, $left);
        $b = self::extractConstantLong($context, $right);
        if (null === $a || null === $b) {
            return null;
        }
        $result = self::foldNativeLongDiv($a, $b);
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
            $context->constantFromFloat($result, 'double')
        );
    }

    /**
     * Native long ⊙ native long `/` — boxed {@see __value__} result.
     */
    public static function binaryNativeLong(Context $context, LlvmValue $left, LlvmValue $right): Variable
    {
        $slot = JitValueBox::alloc($context);
        $slotPtr = JitValueBox::pointer($context, $slot);
        self::writeBoxedNativeLongPair($context, $left, $right, $slotPtr);

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VALUE, $slotPtr);
    }

    /**
     * Write long or double quotient into an existing value slot.
     */
    public static function writeBoxedNativeLongPair(
        Context $context,
        LlvmValue $left,
        LlvmValue $right,
        LlvmValue $slotPtr
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'exact_long_div_cont');
        $i64 = $context->getTypeFromString('int64');
        $f64 = $context->getTypeFromString('double');
        $a = $context->builder->intCast($left, $i64);
        $b = $context->builder->intCast($right, $i64);

        JitNumericDivisionGuard::emitZeroLongDivisorGuard($context, $b, 'Division by zero');

        $intMin = $i64->constInt(\PHP_INT_MIN, true);
        $negOne = $i64->constInt(-1, true);
        $minNegOne = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $a, $intMin),
            $context->builder->icmp(Builder::INT_EQ, $b, $negOne)
        );

        $rem = $context->builder->signedRem($a, $b);
        $isExact = $context->builder->icmp(Builder::INT_EQ, $rem, $i64->constInt(0, false));
        $useLong = $context->builder->and(
            $context->builder->not($minNegOne),
            $isExact
        );

        $longBlock = BasicBlockHelper::append($context, 'exact_long_div_long');
        $doubleBlock = BasicBlockHelper::append($context, 'exact_long_div_double');
        $doneBlock = BasicBlockHelper::append($context, 'exact_long_div_done');
        $context->builder->branchIf($useLong, $longBlock, $doubleBlock);

        $context->builder->positionAtEnd($longBlock);
        $quot = $context->builder->signedDiv($a, $b);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $slotPtr,
            $quot
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doubleBlock);
        $ad = $context->builder->siToFp($a, $f64);
        $bd = $context->builder->siToFp($b, $f64);
        $fd = $context->builder->fdiv($ad, $bd);
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $slotPtr,
            $fd
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        JitValueBox::publishAfterWrite($context, $slotPtr);
    }

    /** @return int|float */
    private static function foldNativeLongDiv(int $left, int $right)
    {
        if (0 === $right) {
            throw new \DivisionByZeroError('Division by zero');
        }
        if (\PHP_INT_MIN === $left && -1 === $right) {
            return (float) \PHP_INT_MIN / -1.0;
        }
        if (0 === $left % $right) {
            return intdiv($left, $right);
        }

        return $left / $right;
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
            || \PHPLLVM\Value::KIND_CONSTANT_INT !== $var->value->getKind()
        ) {
            return null;
        }

        return (int) $context->llvm->lib->LLVMConstIntGetSExtValue($var->value->value);
    }
}
