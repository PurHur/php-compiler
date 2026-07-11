<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * cosh() for compiled JIT/AOT modules (#15156, php-in-PHP).
 *
 * SSOT: {@see VmMath::cosh()}
 * php-src: ext/standard/math.c — PHP_FUNCTION(cosh)
 */
final class CoshJitHelper
{
    public static function coshArgv(float $num): float
    {
        return VmMath::cosh($num);
    }
}
