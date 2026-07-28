<?php

/**
 * Repro #24156 — thin standalone AOT Closure callbacks via NestedClosureInvoke.
 */
echo array_reduce([1, 2, 3], fn($c, $x) => $c + $x, 0), "\n";
$a = [3, 1, 2];
usort($a, fn($x, $y) => $x <=> $y);
echo implode(',', $a), "\n";
$r = array_map(fn($x) => $x * 2, [1, 2, 3]);
echo implode(',', $r), "\n";
