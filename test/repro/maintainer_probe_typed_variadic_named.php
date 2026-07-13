<?php

declare(strict_types=1);

function f(int ...$args): int
{
    return array_sum($args);
}

echo f(a: 1, b: 2, c: 3), "\n";
