<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * rad2deg() for compiled JIT/AOT modules (#15143, php-in-PHP).
 *
 * SSOT: {@see VmMath::rad2deg()}
 * php-src: ext/standard/math.c — PHP_FUNCTION(rad2deg)
 */
final class Rad2degJitHelper
{
    public static function rad2degArgv(float $num): float
    {
        return VmMath::rad2deg($num);
    }
}
