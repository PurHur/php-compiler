<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * expm1() for compiled JIT/AOT modules (#15157, php-in-PHP).
 *
 * SSOT: {@see VmMath::expm1()}
 * php-src: ext/standard/math.c — PHP_FUNCTION(expm1)
 */
final class Expm1JitHelper
{
    public static function expm1Argv(float $num): float
    {
        return VmMath::expm1($num);
    }
}
