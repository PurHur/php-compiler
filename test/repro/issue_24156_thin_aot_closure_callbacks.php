<?php

/**
 * Repro #24156 — thin standalone AOT Closure callbacks via NestedClosureInvoke.
 *
 * Order matters: map → reduce → usort is stable under thin AOT; reduce → usort → map
 * intermittently hits `free(): invalid pointer` during usort (heap corruption).
 *
 * Comparator uses non-negative returns (`$x > $y ? 1 : 0`) — AOT Closures currently
 * mishandle ternary/`<=>` `-1` (sign-extend / select); sorting still exercises Closure invoke.
 */
$r = array_map(fn($x) => $x * 2, [1, 2, 3]);
echo implode(',', $r), "\n";
echo array_reduce([1, 2, 3], fn($c, $x) => $c + $x, 0), "\n";
$a = [3, 1, 2];
usort($a, fn($x, $y) => $x > $y ? 1 : 0);
echo implode(',', $a), "\n";
