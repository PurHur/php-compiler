<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * deg2rad() for compiled JIT/AOT modules (#15143, #27400, php-in-PHP).
 *
 * Inline multiply (same formula as {@see VmMath::deg2rad}) — avoid NestedJIT
 * cross-class stubs that zero VmMath::* under thin standalone AOT (#26996).
 * php-src: ext/standard/math.c — PHP_FUNCTION(deg2rad)
 */
final class Deg2radJitHelper
{
    public static function deg2radArgv(float $num): float
    {
        return (\M_PI / 180.0) * $num;
    }
}
