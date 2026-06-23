<?php

declare(strict_types=1);

$rows = [
    ['x' => 1, 'y' => 'a'],
    ['x' => 2, 'y' => 'b'],
    ['x' => 3, 'y' => 'c'],
];
$r1 = array_column($rows, 'x', 'y');
$r2 = array_column([['x' => 1], ['x' => 2]], null, 'x');
$r3 = array_column([['n' => 'a'], ['n' => 'b']], 'n');
$r4 = array_column([['k' => 1], ['k' => 1]], 'k', 'k');
echo var_export($r1, true), "\n";
echo var_export($r2, true), "\n";
echo var_export($r3, true), "\n";
echo var_export($r4, true), "\n";
if ($r1 !== ['a' => 1, 'b' => 2, 'c' => 3]
    || $r2 !== [1 => ['x' => 1], 2 => ['x' => 2]]
    || $r3 !== ['a', 'b']
    || $r4 !== [1 => 1]) {
    exit(1);
}
