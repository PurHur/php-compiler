<?php

declare(strict_types=1);

/**
 * Typed `array` formals — by-value HT must not free under caller (#36386).
 */

function count_a(array $a): int
{
    return count($a);
}

function first(array $a): int
{
    return $a[0];
}

$a = [5, 6];
echo count_a($a), ':', $a[0], ':', first($a), "\n";
echo count_a([7, 8, 9]), "\n";
