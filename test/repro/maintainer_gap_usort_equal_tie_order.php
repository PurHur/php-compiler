<?php
// Maintainer repro for #13762 — usort() equal-comparator ties preserve insertion order.
$rows = [
    ['id' => 'a', 'v' => 1],
    ['id' => 'c', 'v' => 2],
    ['id' => 'b', 'v' => 2],
];
usort($rows, static fn ($x, $y) => $x['v'] <=> $y['v']);
$order = implode(',', array_column($rows, 'id'));
echo 'a,c,b' === $order ? "ok\n" : "fail: row order $order expected a,c,b\n";
