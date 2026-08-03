<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * deg2rad() for compiled JIT/AOT modules (#15143, #26996, php-in-PHP).
 *
 * Kernel path: {@see phpc_deg2rad_kernel}; VM SSOT remains VmMath::deg2rad.
 * Calling VmMath::deg2rad from this helper re-enters under NestedJIT and yields 0
 * under thin standalone AOT (#26996 — cos/ceil/sqrt peer #27005 / #27003 / #20664).
 * php-src: ext/standard/math.c — PHP_FUNCTION(deg2rad)
 */
final class Deg2radJitHelper
{
    public static function deg2radArgv(float $num): float
    {
        return \phpc_deg2rad_kernel($num);
    }
}
