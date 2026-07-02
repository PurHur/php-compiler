<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * fmod() for compiled JIT/AOT modules (#15072, php-in-PHP).
 *
 * SSOT: {@see VmMath::fmod()}
 * php-src: ext/standard/math.c — PHP_FUNCTION(fmod)
 */
final class FmodJitHelper
{
    public static function fmodArgv(float $num1, float $num2): float
    {
        return VmMath::fmod($num1, $num2);
    }
}
