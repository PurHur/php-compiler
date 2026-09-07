<?php

declare(strict_types=1);

function work(int $n): int
{
    $s = 0;
    for ($i = 0; $i < $n; ++$i) {
        $s += $i;
    }

    return $s;
}

echo work(10), "\n";
