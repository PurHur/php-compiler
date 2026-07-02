<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * exp() for compiled JIT/AOT modules (#15116, php-in-PHP).
 *
 * SSOT: {@see VmMath::exp()}
 * php-src: ext/standard/math.c — PHP_FUNCTION(exp)
 */
final class ExpJitHelper
{
    public static function expArgv(float $num): float
    {
        return VmMath::exp($num);
    }
}
