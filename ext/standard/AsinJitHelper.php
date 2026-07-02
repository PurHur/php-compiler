<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * asin() for compiled JIT/AOT modules (#15130, php-in-PHP).
 *
 * SSOT: {@see VmMath::asin()}
 * php-src: ext/standard/math.c — PHP_FUNCTION(asin)
 */
final class AsinJitHelper
{
    public static function asinArgv(float $num): float
    {
        return VmMath::asin($num);
    }
}
