<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * nextafter() for compiled JIT/AOT modules (#15062, php-in-PHP).
 *
 * SSOT: {@see VmMath::nextafter()}
 * php-src: ext/standard/math.c — PHP_FUNCTION(nextafter)
 */
final class NextafterJitHelper
{
    public static function nextafterArgv(float $num, float $next): float
    {
        return VmMath::nextafter($num, $next);
    }
}
