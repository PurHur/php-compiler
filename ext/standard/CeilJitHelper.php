<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * ceil() for compiled JIT/AOT modules (#15129, php-in-PHP).
 *
 * SSOT: {@see VmMath::ceil()}
 * php-src: ext/standard/math.c — PHP_FUNCTION(ceil)
 */
final class CeilJitHelper
{
    public static function ceilArgv(float $num): float
    {
        return VmMath::ceil($num);
    }
}
