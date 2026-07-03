<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * atanh() for compiled JIT/AOT modules (#15221, php-in-PHP).
 *
 * SSOT: {@see VmMath::atanh()}
 * php-src: ext/standard/math.c — PHP_FUNCTION(atanh)
 */
final class AtanhJitHelper
{
    public static function atanhArgv(float $num): float
    {
        return VmMath::atanh($num);
    }
}
