<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * log10() for compiled JIT/AOT modules (#15101, php-in-PHP).
 *
 * SSOT: {@see VmMath::log10()}
 * php-src: ext/standard/math.c — PHP_FUNCTION(log10)
 */
final class Log10JitHelper
{
    public static function log10Argv(float $num): float
    {
        return VmMath::log10($num);
    }
}
