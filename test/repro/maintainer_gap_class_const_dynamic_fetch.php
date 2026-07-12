<?php

declare(strict_types=1);

/**
 * Issue #18093 — dynamic class const fetch must compile without TypeReconstructor warnings.
 */

class C
{
    public const FOO = 'bar';
}

$name = 'FOO';
echo constant(C::class.'::'.$name), "\n";
echo C::{$name}, "\n";
