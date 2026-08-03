<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * rad2deg() for compiled JIT/AOT modules (#15143, #27400, php-in-PHP).
 *
 * Inline multiply (same formula as {@see VmMath::rad2deg}) — avoid NestedJIT
 * cross-class stubs that zero VmMath::* under thin standalone AOT (#27006).
 * php-src: ext/standard/math.c — PHP_FUNCTION(rad2deg)
 */
final class Rad2degJitHelper
{
    public static function rad2degArgv(float $num): float
    {
        return (180.0 / \M_PI) * $num;
    }
}
