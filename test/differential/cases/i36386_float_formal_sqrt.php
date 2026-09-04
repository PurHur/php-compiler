<?php

declare(strict_types=1);

/**
 * #36386 — float $x formal in a loop stays native; sqrt on float must match Zend.
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

echo work(2.5, 100), "\n";
echo work(0.25, 50), "\n";
