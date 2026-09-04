<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * deg2rad() NestedJIT-safe multiply reference (#15143, #27400, php-in-PHP).
 *
 * AOT/JIT hot path uses inline {@code fmul} by {@code M_PI/180} via
 * {@see \PHPCompiler\JIT\Builtin\MathDeg2rad} (#36386 / peer MathRad2deg).
 * This helper remains for NestedJIT-safe reference when NestedJIT cannot emit
 * the constant multiply in-module.
 * Avoid {@see VmMath::deg2rad} — NestedJIT re-enters MathDeg2rad under thin AOT.
 * php-src: ext/standard/math.c — PHP_FUNCTION(deg2rad)
 */
final class Deg2radJitHelper
{
    public static function deg2radArgv(float $num): float
    {
        return (\M_PI / 180.0) * $num;
    }
}
