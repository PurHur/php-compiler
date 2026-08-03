<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * is_finite() NestedJIT helper (#15188, #27021).
 * Kernel path avoids NestedJIT re-entry into phpc_is_finite.
 */
final class IsFiniteJitHelper
{
    public static function isFiniteArgv(float $num): bool
    {
        return \phpc_is_finite_kernel($num);
    }
}
