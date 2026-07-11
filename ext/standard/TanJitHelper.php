<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * tan() for compiled JIT/AOT modules (#15088, php-in-PHP).
 *
 * SSOT: {@see VmMath::tan()}
 * php-src: ext/standard/math.c — PHP_FUNCTION(tan)
 */
final class TanJitHelper
{
    public static function tanArgv(float $num): float
    {
        return VmMath::tan($num);
    }
}
