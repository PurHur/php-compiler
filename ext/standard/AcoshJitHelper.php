<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * acosh() for compiled JIT/AOT modules (#15221, php-in-PHP).
 *
 * SSOT: {@see VmMath::acosh()}
 * php-src: ext/standard/math.c — PHP_FUNCTION(acosh)
 */
final class AcoshJitHelper
{
    public static function acoshArgv(float $num): float
    {
        return VmMath::acosh($num);
    }
}
