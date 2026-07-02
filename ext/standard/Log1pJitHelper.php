<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * log1p() for compiled JIT/AOT modules (#15157, php-in-PHP).
 *
 * SSOT: {@see VmMath::log1p()}
 * php-src: ext/standard/math.c — PHP_FUNCTION(log1p)
 */
final class Log1pJitHelper
{
    public static function log1pArgv(float $num): float
    {
        return VmMath::log1p($num);
    }
}
