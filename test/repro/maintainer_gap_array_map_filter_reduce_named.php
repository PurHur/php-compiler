<?php

declare(strict_types=1);

// Issue #10047 — array_map()/array_filter()/array_reduce() callback:/array:/initial: named parameters

$mapped = array_map(callback: fn ($x) => $x * 2, array: [1, 2, 3]);
if ($mapped !== [2, 4, 6]) {
    echo 'array_map fail: ', var_export($mapped, true), "\n";
    exit(1);
}
echo "map ok\n";

$filtered = array_filter(array: [1, 2, 3, 4], callback: fn ($x) => $x % 2 === 0);
if ($filtered !== [1 => 2, 3 => 4]) {
    echo 'array_filter fail: ', var_export($filtered, true), "\n";
    exit(1);
}
echo "filter ok\n";

$reduced = array_reduce(array: [1, 2, 3], callback: fn ($c, $i) => $c + $i, initial: 0);
if ($reduced !== 6) {
    echo 'array_reduce fail: ', var_export($reduced, true), "\n";
    exit(1);
}
echo "reduce ok\n";
