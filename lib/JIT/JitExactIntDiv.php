<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value as LlvmValue;

/**
 * Zend {@code div_function} for IS_LONG ÷ IS_LONG (#35337).
 *
 * Exact quotients stay {@code IS_LONG}; inexact results and {@code ZEND_LONG_MIN / -1}
 * become double. #31968 correctly stopped truncating {@code 7/2} via LLVM {@code sdiv},
 * but overcorrected by always {@code fdiv} — {@code 10/2} must remain {@code int(5)}.
 *
 * @see php-src Zend/zend_operators.c div_function
 */
final class JitExactIntDiv
{
    /**
     * Compile-time fold when both operands are constant longs.
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
        if (0 === $b) {
            // Runtime DivisionByZeroError — do not fold.
            return null;
        }
        if (\PHP_INT_MIN === $a && -1 === $b) {
            return new Variable(
                $context,
                Variable::TYPE_NATIVE_DOUBLE,
                Variable::KIND_VALUE,
                $context->constantFromFloat((float) $a / (float) $b, 'double')
            );
        }
        if (0 === ($a % $b)) {
            return new Variable(
                $context,
                Variable::TYPE_NATIVE_LONG,
                Variable::KIND_VALUE,
                $context->constantFromInteger(intdiv($a, $b), 'int64')
            );
        }

        return new Variable(
            $context,
            Variable::TYPE_NATIVE_DOUBLE,
            Variable::KIND_VALUE,
            $context->constantFromFloat((float) $a / (float) $b, 'double')
        );
    }

    /**
     * Native long ÷ native long → long or double value box (#35337).
     */
    public static function binaryNativeLong(
        Context $context,
        LlvmValue $left,
        LlvmValue $right
    ): Variable {
        $slot = JitValueBox::alloc($context);
        $slotPtr = JitValueBox::pointer($context, $slot);
        self::writeBoxed($context, $left, $right, $slotPtr);

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VALUE, $slotPtr);
    }

    /**
     * Write long or double quotient into an existing {@see __value__} slot.
     */
    public static function writeBoxed(
        Context $context,
        LlvmValue $left,
        LlvmValue $right,
        LlvmValue $slotPtr
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'exact_int_div_cont');
        $i64 = $context->getTypeFromString('int64');
        $f64 = $context->getTypeFromString('double');
        $a = $context->builder->intCast($left, $i64);
        $b = $context->builder->intCast($right, $i64);

        JitNumericDivisionGuard::emitZeroLongDivisorGuard($context, $b, 'Division by zero');

        $negOne = $i64->constInt(-1, true);
        $intMin = $i64->constInt(\PHP_INT_MIN, true);
        $isNegOne = $context->builder->icmp(Builder::INT_EQ, $b, $negOne);
        $isIntMin = $context->builder->icmp(Builder::INT_EQ, $a, $intMin);
        $overflow = $context->builder->and($isNegOne, $isIntMin);

        $ovBlock = BasicBlockHelper::append($context, 'exact_int_div_overflow');
        $checkBlock = BasicBlockHelper::append($context, 'exact_int_div_check');
        $longBlock = BasicBlockHelper::append($context, 'exact_int_div_long');
        $floatBlock = BasicBlockHelper::append($context, 'exact_int_div_float');
        $doneBlock = BasicBlockHelper::append($context, 'exact_int_div_done');

        // LONG_MIN / -1 → double before srem/sdiv (LLVM UB on that pair).
        $context->builder->branchIf($overflow, $ovBlock, $checkBlock);

        $context->builder->positionAtEnd($ovBlock);
        $context->builder->branch($floatBlock);

        $context->builder->positionAtEnd($checkBlock);
        $rem = $context->builder->signedRem($a, $b);
        $exact = $context->builder->icmp(
            Builder::INT_EQ,
            $rem,
            $i64->constInt(0, false)
        );
        $context->builder->branchIf($exact, $longBlock, $floatBlock);

        $context->builder->positionAtEnd($longBlock);
        $quot = $context->builder->signedDiv($a, $b);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $slotPtr,
            $quot
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($floatBlock);
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

    private static function extractConstantLong(Context $context, Variable $var): ?int
    {
        // Mirror JitLongArithOverflow::extractConstantLong (private).
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
            || LlvmValue::KIND_CONSTANT_INT !== $var->value->getKind()
        ) {
            return null;
        }

        return (int) $context->llvm->lib->LLVMConstIntGetSExtValue($var->value->value);
    }
}
