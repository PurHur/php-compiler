<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/** is_infinite() NestedJIT helper (#15174, #27021). */
final class IsInfiniteJitHelper
{
    public static function isInfiniteArgv(float $num): bool
    {
        return \phpc_is_infinite_kernel($num);
    }
}
