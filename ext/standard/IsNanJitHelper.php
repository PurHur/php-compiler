<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * is_nan() for compiled JIT/AOT modules (#15173, php-in-PHP).
 *
 * SSOT: PHP `\is_nan()` (integers never reach this helper — lowered in is_nan.php).
 * php-src: ext/standard/math.c — PHP_FUNCTION(is_nan)
 */
final class IsNanJitHelper
{
    public static function isNanArgv(float $num): bool
    {
        return \is_nan($num);
    }
}
