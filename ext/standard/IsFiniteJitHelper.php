<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * is_finite() for compiled JIT/AOT modules (#15188, php-in-PHP).
 *
 * SSOT: PHP `\is_finite()` (integers never reach this helper — lowered in is_finite.php).
 * php-src: ext/standard/math.c — PHP_FUNCTION(is_finite)
 */
final class IsFiniteJitHelper
{
    public static function isFiniteArgv(float $num): bool
    {
        return \is_finite($num);
    }
}
