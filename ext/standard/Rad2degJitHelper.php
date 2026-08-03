<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * rad2deg() for compiled JIT/AOT modules (#15143, #26996 / #27006, php-in-PHP).
 *
 * Kernel path: {@see phpc_rad2deg_kernel}; VM SSOT remains VmMath::rad2deg.
 * Calling VmMath::rad2deg from this helper re-enters under NestedJIT and yields 0
 * under thin standalone AOT (#27006 — cos/ceil/sqrt peer #27005 / #27003 / #20664).
 * php-src: ext/standard/math.c — PHP_FUNCTION(rad2deg)
 */
final class Rad2degJitHelper
{
    public static function rad2degArgv(float $num): float
    {
        return \phpc_rad2deg_kernel($num);
    }
}
