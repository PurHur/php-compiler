<?php
// Maintainer repro for #10213 — comparator return must not leak into sorted elements.
$a = [['a', 1], ['b', 1]];
usort($a, fn($x, $y) => $x[1] <=> $y[1] ?: strcmp($x[0], $y[0]));
var_export($a);
echo "\n";
