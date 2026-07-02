<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * sinh() for compiled JIT/AOT modules (#15156, php-in-PHP).
 *
 * SSOT: {@see VmMath::sinh()}
 * php-src: ext/standard/math.c — PHP_FUNCTION(sinh)
 */
final class SinhJitHelper
{
    public static function sinhArgv(float $num): float
    {
        return VmMath::sinh($num);
    }
}
