<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * rad2deg() NestedJIT-safe multiply reference (#15143, #27400, php-in-PHP).
 *
 * AOT/JIT hot path uses inline {@code fmul} by {@code 180/M_PI} via
 * {@see \PHPCompiler\JIT\Builtin\MathRad2deg} (#36386 / peer MathDeg2rad).
 * This helper remains for NestedJIT-safe reference when NestedJIT cannot emit
 * the constant multiply in-module.
 * Avoid {@see VmMath::rad2deg} — NestedJIT re-enters MathRad2deg under thin AOT.
 * php-src: ext/standard/math.c — PHP_FUNCTION(rad2deg)
 */
final class Rad2degJitHelper
{
    public static function rad2degArgv(float $num): float
    {
        return (180.0 / \M_PI) * $num;
    }
}
