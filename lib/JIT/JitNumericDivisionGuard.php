<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\VM\VmNumericDivisionGuard;
use PHPLLVM\Value;

/**
 * JIT trampoline for / and % zero-divisor guards (#9976).
 *
 * SSOT: {@see \PHPCompiler\VM\VmNumericDivisionGuard}
 */
final class JitNumericDivisionGuard
{
    public static function emitZeroLongDivisorGuard(Context $context, Value $divisor, string $message): void
    {
        VmNumericDivisionGuard::emitZeroLongDivisorGuard($context, $divisor, $message);
    }

    public static function emitZeroDoubleDivisorGuard(Context $context, Value $divisor, string $message): void
    {
        VmNumericDivisionGuard::emitZeroDoubleDivisorGuard($context, $divisor, $message);
    }

    /**
     * Integer `%` with Zend LONG_MIN/-1 → 0 (#32285).
     */
    public static function signedModulo(Context $context, Value $dividend, Value $divisor): Value
    {
        return VmNumericDivisionGuard::signedModulo($context, $dividend, $divisor);
    }

    public static function emitIntMinNegOneOverflowGuard(
        Context $context,
        Value $dividend,
        Value $divisor,
        string $message
    ): void {
        VmNumericDivisionGuard::emitIntMinNegOneOverflowGuard($context, $dividend, $divisor, $message);
    }

    /** Zend << / >> negative shift count (#21912). */
    public static function emitNegativeBitShiftCountGuard(Context $context, Value $shiftCount): void
    {
        VmNumericDivisionGuard::emitNegativeBitShiftCountGuard($context, $shiftCount);
    }
}
