<?php

declare(strict_types=1);

/**
 * Repro for #36386: float $x formal read inside a loop should stay native double.
 */
function work(float $x, int $n): float
{
    $s = 0.0;
    for ($i = 0; $i < $n; ++$i) {
        $s += $x;
        $s += sqrt($x);
    }

    return $s;
}

echo work(2.5, 1000), "\n";
