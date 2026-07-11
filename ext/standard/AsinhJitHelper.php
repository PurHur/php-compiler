<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * asinh() for compiled JIT/AOT modules (#15221, php-in-PHP).
 *
 * SSOT: {@see VmMath::asinh()}
 * php-src: ext/standard/math.c — PHP_FUNCTION(asinh)
 */
final class AsinhJitHelper
{
    public static function asinhArgv(float $num): float
    {
        return VmMath::asinh($num);
    }
}
