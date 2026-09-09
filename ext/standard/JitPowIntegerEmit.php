<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\MathFpow;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\DiscardedPureCallElision;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * Integer {@code pow}/{@code **} chained-smul emit for compile-time exponents
 * (#36387 / #36386). Extracted from {@see JitPow} so gen-0 spine gets a
 * separate TU for the exponent table; Low/Mid/Exponents50to69/Exponents70to79/
 * Exponents80to85/Exponents86to88/Exponents80to91/Exponents92to95/High are sibling TUs (#36387).
 *
 * No new C ABI. php-src: Zend/zend_operators.c {@code pow_function} /
 * {@code zend_pow} / {@code mul_function}; ext/standard/math.c
 * {@code PHP_FUNCTION(pow)}.
 */
require_once __DIR__.'/JitPowIntegerEmitLow.php';
require_once __DIR__.'/JitPowIntegerEmitMid.php';
require_once __DIR__.'/JitPowIntegerEmitExponents50to69.php';
require_once __DIR__.'/JitPowIntegerEmitExponents70to79.php';
require_once __DIR__.'/JitPowIntegerEmitExponents80to85.php';
require_once __DIR__.'/JitPowIntegerEmitExponents86to88.php';
require_once __DIR__.'/JitPowIntegerEmitExponents80to91.php';
require_once __DIR__.'/JitPowIntegerEmitExponents92to95.php';
require_once __DIR__.'/JitPowIntegerEmitHigh.php';

final class JitPowIntegerEmit
{
    /**
     * Integer ** via MathFpow — avoids broken __phpc_pow_int on boxed operands (#35978).
     *
     * Compile-time exponent {@code 0} → {@code 1}, {@code 1} → identity,
     * {@code 2} → {@code base * base}, {@code 3} → {@code base^3},
     * {@code 4} → {@code (base*base)^2}, {@code 5} → {@code base^3 * base^2},
     * {@code 6} → {@code (base^3)^2}, {@code 7} → {@code (base^3)^2 * base},
     * {@code 8} → {@code ((base*base)^2)^2}, {@code 9} →
     * {@code ((base^3)^2)*(base^3)}, {@code 10} → {@code (base^5)^2},
     * {@code 11} → {@code (base^5)^2 * base}, {@code 12} →
     * {@code (base^6)^2}, {@code 13} → {@code (base^6)^2 * base},
     * {@code 14} → {@code (base^7)^2}, {@code 15} → {@code (base^7)^2 * base},
     * {@code 16} → {@code (base^8)^2}, {@code 17} → {@code (base^8)^2 * base}
     * with chained smul overflow→float omit {@code llvm.pow.f64}
     * (#36386; php-src {@code pow_function} / {@code zend_pow} /
     * {@code mul_function}).
     */
    public static function emitIntegerPowViaMathFpow(
        Context $context,
        Value $slotPtr,
        JITVariable $base,
        JITVariable $exp
    ): void {
        $expFold = DiscardedPureCallElision::nativeLongPowCompileTimeExponentFold($exp);
        if (JitPowIntegerEmitLow::tryEmitIntegerPowViaMathFpow(
            $context,
            $slotPtr,
            $base,
            $exp,
            $expFold
        )) {
            return;
        }

        if (JitPowIntegerEmitExponents50to69::tryEmit(
            $context,
            $slotPtr,
            $base,
            $exp,
            $expFold
        )) {
            return;
        }

        if (JitPowIntegerEmitMid::tryEmitIntegerPowViaMathFpow(
            $context,
            $slotPtr,
            $base,
            $exp,
            $expFold
        )) {
            return;
        }
        if (JitPowIntegerEmitExponents70to79::tryEmitIntegerPowViaMathFpow(
            $context,
            $slotPtr,
            $base,
            $exp,
            $expFold
        )) {
            return;
        }
        if (JitPowIntegerEmitExponents80to85::tryEmitIntegerPowViaMathFpow(
            $context,
            $slotPtr,
            $base,
            $exp,
            $expFold
        )) {
            return;
        }
        if (JitPowIntegerEmitExponents86to88::tryEmitIntegerPowViaMathFpow(
            $context,
            $slotPtr,
            $base,
            $exp,
            $expFold
        )) {
            return;
        }
        if (JitPowIntegerEmitExponents80to91::tryEmitIntegerPowViaMathFpow(
            $context,
            $slotPtr,
            $base,
            $exp,
            $expFold
        )) {
            return;
        }
        if (JitPowIntegerEmitExponents92to95::tryEmitIntegerPowViaMathFpow(
            $context,
            $slotPtr,
            $base,
            $exp,
            $expFold
        )) {
            return;
        }
        if (JitPowIntegerEmitHigh::tryEmitIntegerPowViaMathFpow(
            $context,
            $slotPtr,
            $base,
            $exp,
            $expFold
        )) {
            return;
        }

        MathFpow::ensureLinked($context);
        $baseL = JitLongArg::lower($context, $base, 'pow() base');
        $expL = JitLongArg::lower($context, $exp, 'pow() exponent');
        $double = $context->getTypeFromString('double');
        $i64 = $context->getTypeFromString('int64');
        $baseD = $context->builder->siToFp($baseL, $double);
        $expD = $context->builder->siToFp($expL, $double);
        $fres = MathFpow::invoke($context, $baseD, $expD);
        $longRes = $context->builder->fpToSi($fres, $i64);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $slotPtr,
            $longRes
        );
    }
}
