<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * floor() for compiled JIT/AOT modules (#15128, #27004, php-in-PHP).
 *
 * Kernel path: {@see phpc_floor_kernel}; VM SSOT remains VmMath::floor.
 * Calling VmMath::floor / \floor from this helper re-enters the MathFloor bridge under
 * NestedJIT and yields 0 under thin standalone AOT (#27004 — hypot/sqrt/ceil peer #20664/#27003).
 * php-src: ext/standard/math.c — PHP_FUNCTION(floor)
 */
final class FloorJitHelper
{
    public static function floorArgv(float $num): float
    {
        return \phpc_floor_kernel($num);
    }
}
