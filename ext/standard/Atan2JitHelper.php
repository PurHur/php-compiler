<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * atan2() for compiled JIT/AOT modules (#15102, php-in-PHP).
 *
 * SSOT: {@see VmMath::atan2()}
 * php-src: ext/standard/math.c — PHP_FUNCTION(atan2)
 */
final class Atan2JitHelper
{
    public static function atan2Argv(float $y, float $x): float
    {
        return VmMath::atan2($y, $x);
    }
}
