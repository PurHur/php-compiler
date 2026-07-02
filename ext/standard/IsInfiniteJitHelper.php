<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * is_infinite() for compiled JIT/AOT modules (#15174, php-in-PHP).
 *
 * SSOT: PHP `\is_infinite()` (integers never reach this helper — lowered in is_infinite.php).
 * php-src: ext/standard/math.c — PHP_FUNCTION(is_infinite)
 */
final class IsInfiniteJitHelper
{
    public static function isInfiniteArgv(float $num): bool
    {
        return \is_infinite($num);
    }
}
