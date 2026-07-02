<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * ldexp() for compiled JIT/AOT modules (#15073, php-in-PHP).
 *
 * SSOT: {@see VmMath::ldexp()}
 * php-src: ext/standard/math.c — PHP_FUNCTION(ldexp)
 */
final class LdexpJitHelper
{
    public static function ldexpArgv(float $num, int $exp): float
    {
        return VmMath::ldexp($num, $exp);
    }
}
