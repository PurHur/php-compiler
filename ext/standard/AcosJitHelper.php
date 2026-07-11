<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * acos() for compiled JIT/AOT modules (#15141, php-in-PHP).
 *
 * SSOT: {@see VmMath::acos()}
 * php-src: ext/standard/math.c — PHP_FUNCTION(acos)
 */
final class AcosJitHelper
{
    public static function acosArgv(float $num): float
    {
        return VmMath::acos($num);
    }
}
