<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/** is_nan() NestedJIT helper (#15173, #27021). */
final class IsNanJitHelper
{
    public static function isNanArgv(float $num): bool
    {
        return \phpc_is_nan_kernel($num);
    }
}
