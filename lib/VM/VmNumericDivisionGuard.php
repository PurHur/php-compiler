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
}
