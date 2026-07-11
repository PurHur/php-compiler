<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * cos() for compiled JIT/AOT modules (#15087, php-in-PHP).
 *
 * SSOT: {@see VmMath::cos()}
 * php-src: ext/standard/math.c — PHP_FUNCTION(cos)
 */
final class CosJitHelper
{
    public static function cosArgv(float $num): float
    {
        return VmMath::cos($num);
    }
}
