<?php

declare(strict_types=1);

/**
 * Bootstrap AOT: user function with native int param (Native::compileArg int64).
 */

function add(int $a, int $b): int
{
    return $a + $b;
}

echo (string) add(2, 3);
