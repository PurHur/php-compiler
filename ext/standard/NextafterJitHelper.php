<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * nextafter() for compiled JIT/AOT modules (#15062, #19259, #27496, php-in-PHP).
 *
 * Kernel path: {@see phpc_nextafter_kernel} (IEEE bitcast leaf, no libc);
 * VM SSOT remains VmMath::nextafter.
 * php-src: ext/standard/math.c — PHP_FUNCTION(nextafter)
 */
final class NextafterJitHelper
{
    public static function nextafterArgv(float $num, float $next): float
    {
        return \phpc_nextafter_kernel($num, $next);
    }
}
