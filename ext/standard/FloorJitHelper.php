<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * floor() for compiled JIT/AOT modules (#15128, php-in-PHP).
 *
 * SSOT: {@see VmMath::floor()}
 * php-src: ext/standard/math.c — PHP_FUNCTION(floor)
 */
final class FloorJitHelper
{
    public static function floorArgv(float $num): float
    {
        return VmMath::floor($num);
    }
}
