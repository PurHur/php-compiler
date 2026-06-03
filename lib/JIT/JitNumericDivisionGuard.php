<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Runtime guards for / and % when divisor is zero (issue #5006, Zend/zend_operators.c).
 */
final class JitNumericDivisionGuard
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
}
