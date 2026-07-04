<?php
// Maintainer repro for #13762 — uasort() equal-comparator ties preserve key order.
$rows = [
    'a' => 1,
    'c' => 2,
    'b' => 2,
];
uasort($rows, static fn ($x, $y) => $x <=> $y);
$order = implode(',', array_keys($rows));
echo 'a,c,b' === $order ? "ok\n" : "fail: key order $order expected a,c,b\n";
