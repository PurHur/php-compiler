<?php

declare(strict_types=1);

/**
 * Associative-array heavy — insert + isset + sum (#36385).
 * Sized for gate wall time; larger N belongs in soak runs.
 */

$n = 5000;
$map = [];
for ($i = 0; $i < $n; ++$i) {
    $map['k'.$i] = $i;
}

$sum = 0;
$hits = 0;
for ($i = 0; $i < $n; ++$i) {
    $key = 'k'.($i % $n);
    if (isset($map[$key])) {
        ++$hits;
        $sum += $map[$key];
    }
}

echo $hits, '|', $sum, '|', count($map), "\n";
