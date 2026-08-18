<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\TryCatchHelper;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * SSOT for JIT / and % zero-divisor guards (#5006, zend_operators.c, #9976)
 * and PHP_INT_MIN % -1 → 0 (#32285).
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
     * zend_operators.c mod_function: n % -1 is 0. LLVM srem(INT_MIN, -1) is poison (#32285).
     */
    public static function signedModulo(Context $context, Value $dividend, Value $divisor): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mod_srem_cont');
        $i64 = $context->getTypeFromString('int64');
        $left = $context->builder->intCast($dividend, $i64);
        $right = $context->builder->intCast($divisor, $i64);
        self::emitZeroLongDivisorGuard($context, $right, 'Modulo by zero');
        $negOne = $i64->constInt(-1, true);
        $isNegOne = $context->builder->icmp(Builder::INT_EQ, $right, $negOne);
        $neg1Block = BasicBlockHelper::append($context, 'mod_neg1_zero');
        $sremBlock = BasicBlockHelper::append($context, 'mod_srem');
        $doneBlock = BasicBlockHelper::append($context, 'mod_done');
        $context->builder->branchIf($isNegOne, $neg1Block, $sremBlock);

        $context->builder->positionAtEnd($neg1Block);
        $zero = $i64->constInt(0, false);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($sremBlock);
        $rem = $context->builder->signedRem($left, $right);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($i64, 'mod_result');
        $phi->addIncoming($zero, $neg1Block);
        $phi->addIncoming($rem, $sremBlock);

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
