<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * atan() for compiled JIT/AOT modules (#15142, php-in-PHP).
 *
 * SSOT: {@see VmMath::atan()}
 * php-src: ext/standard/math.c — PHP_FUNCTION(atan)
 */
final class AtanJitHelper
{
    public static function atanArgv(float $num): float
    {
        return VmMath::atan($num);
    }
}
