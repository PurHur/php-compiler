<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * hypot() for compiled JIT/AOT modules (#15074, php-in-PHP).
 *
 * SSOT: {@see VmMath::hypot()}
 * php-src: ext/standard/math.c — PHP_FUNCTION(hypot)
 */
final class HypotJitHelper
{
    public static function hypotArgv(float $x, float $y): float
    {
        return VmMath::hypot($x, $y);
    }
}
