<?php

declare(strict_types=1);

/**
 * Call-heavy — user + builtin call volume (#36385).
 */

function id(int $x): int
{
    return $x;
}

function work(int $n): int
{
    $s = 0;
    for ($i = 0; $i < $n; ++$i) {
        $s += id($i);
        $s += strlen('x');
    }

    return $s;
}

echo work(80000), "\n";
