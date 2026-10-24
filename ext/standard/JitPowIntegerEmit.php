<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\PowLongLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * Integer {@code pow}/{@code **}: both operands lowered to i64, result via
 * {@see PowLongLlvm} (php-src Zend/zend_operators.c pow_function_base — exact int, or the
 * same double continuation on overflow; negative exponent → double).
 *
 * This replaced ~146k lines of per-exponent chained-multiply tables (0..111) whose
 * fallback for every other exponent was `(int) pow((double) $b, (double) $e)` — wrong for
 * results beyond 2^53 and for overflow, which php-src returns as float.
 */
final class JitPowIntegerEmit
{
    public static function emitIntegerPowViaMathFpow(
        Context $context,
        Value $slotPtr,
        JITVariable $base,
        JITVariable $exp
    ): void {
        $i64 = $context->getTypeFromString('int64');
        PowLongLlvm::call(
            $context,
            $slotPtr,
            $context->builder->intCast(JitLongArg::lower($context, $base, 'pow() base'), $i64),
            $context->builder->intCast(JitLongArg::lower($context, $exp, 'pow() exponent'), $i64)
        );
    }
}
