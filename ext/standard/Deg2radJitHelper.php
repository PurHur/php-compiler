<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * deg2rad() for compiled JIT/AOT modules (#15143, php-in-PHP).
 *
 * SSOT: {@see VmMath::deg2rad()}
 * php-src: ext/standard/math.c — PHP_FUNCTION(deg2rad)
 */
final class Deg2radJitHelper
{
    public static function deg2radArgv(float $num): float
    {
        return VmMath::deg2rad($num);
    }
}
