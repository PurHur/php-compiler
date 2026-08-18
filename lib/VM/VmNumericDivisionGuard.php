<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\TryCatchHelper;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * SSOT for JIT / and % zero-divisor guards (#5006, zend_operators.c, #9976).
 *
 * JIT trampoline: {@see \PHPCompiler\JIT\JitNumericDivisionGuard}
 */
final class VmNumericDivisionGuard
{
    public static function emitZeroLongDivisorGuard(Context $context, Value $divisor, string $message): void
    {
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $isZero = $context->builder->icmp(Builder::INT_EQ, $divisor, $zero);
        $okBlock = BasicBlockHelper::append($context, 'numdiv_long_ok');
        $errBlock = BasicBlockHelper::append($context, 'numdiv_long_err');
        $context->builder->branchIf($isZero, $errBlock, $okBlock);
        $context->builder->positionAtEnd($errBlock);
        TryCatchHelper::emitCatchableClassError($context, 'DivisionByZeroError', $message);
        $context->builder->positionAtEnd($okBlock);
    }

    public static function emitZeroDoubleDivisorGuard(Context $context, Value $divisor, string $message): void
    {
        $f64 = $context->getTypeFromString('double');
        $zero = $f64->constReal(0.0);
        $isZero = $context->builder->fcmp(Builder::REAL_OEQ, $divisor, $zero);
        $okBlock = BasicBlockHelper::append($context, 'numdiv_double_ok');
        $errBlock = BasicBlockHelper::append($context, 'numdiv_double_err');
        $context->builder->branchIf($isZero, $errBlock, $okBlock);
        $context->builder->positionAtEnd($errBlock);
        TryCatchHelper::emitCatchableClassError($context, 'DivisionByZeroError', $message);
        $context->builder->positionAtEnd($okBlock);
    }

    /**
     * PHP `%` on zend_long: zero divisor throws; ZEND_LONG_MIN % -1 is 0.
     *
     * LLVM `srem` of INT_MIN and -1 is undefined (x86 SIGFPE / wrap). php-src
     * `mod_function()` special-cases that pair before `%`.
     *
     * @see php-src Zend/zend_operators.c mod_function()
     */
    public static function signedRemPhpLong(Context $context, Value $dividend, Value $divisor): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $left = $context->builder->intCast($dividend, $i64);
        $right = $context->builder->intCast($divisor, $i64);

        if (
            Value::KIND_CONSTANT_INT === $left->getKind()
            && Value::KIND_CONSTANT_INT === $right->getKind()
        ) {
            $l = (int) $context->llvm->lib->LLVMConstIntGetSExtValue($left->value);
            $r = (int) $context->llvm->lib->LLVMConstIntGetSExtValue($right->value);
            if (0 === $r) {
                self::emitZeroLongDivisorGuard($context, $right, 'Modulo by zero');

                return $i64->constInt(0, false);
            }
            if (\PHP_INT_MIN === $l && -1 === $r) {
                return $i64->constInt(0, false);
            }

            return $i64->constInt($l % $r, true);
        }

        self::emitZeroLongDivisorGuard($context, $right, 'Modulo by zero');
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mod_intmin_cont');
        $intMin = $i64->constInt(\PHP_INT_MIN, true);
        $negOne = $i64->constInt(-1, true);
        $overflow = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $left, $intMin),
            $context->builder->icmp(Builder::INT_EQ, $right, $negOne)
        );
        $zeroBlock = BasicBlockHelper::append($context, 'mod_intmin_negone');
        $remBlock = BasicBlockHelper::append($context, 'mod_srem');
        $doneBlock = BasicBlockHelper::append($context, 'mod_done');
        $context->builder->branchIf($overflow, $zeroBlock, $remBlock);

        $context->builder->positionAtEnd($zeroBlock);
        $zero = $i64->constInt(0, false);
        $zeroEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($remBlock);
        $rem = $context->builder->signedRem($left, $right);
        $remEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($i64, 'mod_result');
        $phi->addIncoming($zero, $zeroEnd);
        $phi->addIncoming($rem, $remEnd);

        return $phi;
    }

    /** intdiv(PHP_INT_MIN, -1) — php-src ext/standard/math.c (#4724). */
    public static function emitIntMinNegOneOverflowGuard(
        Context $context,
        Value $dividend,
        Value $divisor,
        string $message
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $intMin = $i64->constInt(\PHP_INT_MIN, true);
        $negOne = $i64->constInt(-1, true);
        $isIntMin = $context->builder->icmp(Builder::INT_EQ, $dividend, $intMin);
        $isNegOne = $context->builder->icmp(Builder::INT_EQ, $divisor, $negOne);
        $overflow = $context->builder->and($isIntMin, $isNegOne);
        $okBlock = BasicBlockHelper::append($context, 'intdiv_overflow_ok');
        $errBlock = BasicBlockHelper::append($context, 'intdiv_overflow_err');
        $context->builder->branchIf($overflow, $errBlock, $okBlock);
        $context->builder->positionAtEnd($errBlock);
        TryCatchHelper::emitCatchableClassError($context, 'ArithmeticError', $message);
        $context->builder->positionAtEnd($okBlock);
    }

    /**
     * Zend shift_left/right_function — negative count is catchable ArithmeticError (#21912).
     */
    public static function emitNegativeBitShiftCountGuard(Context $context, Value $shiftCount): void
    {
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $count = $context->builder->intCast($shiftCount, $i64);
        $isNeg = $context->builder->icmp(Builder::INT_SLT, $count, $zero);
        $okBlock = BasicBlockHelper::append($context, 'bitshift_count_ok');
        $errBlock = BasicBlockHelper::append($context, 'bitshift_count_err');
        $context->builder->branchIf($isNeg, $errBlock, $okBlock);
        $context->builder->positionAtEnd($errBlock);
        TryCatchHelper::emitCatchableClassError($context, 'ArithmeticError', 'Bit shift by negative number');
        $context->builder->positionAtEnd($okBlock);
    }
}
