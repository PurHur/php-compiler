<?php

declare(strict_types=1);

/**
 * Closure-heavy — create + invoke (#36385).
 */

$n = 10000;
$sum = 0;
for ($i = 0; $i < $n; ++$i) {
    $f = static function (int $x) use ($i): int {
        return $x + $i;
    };
    $sum += $f($i);
}

echo $sum, "\n";
