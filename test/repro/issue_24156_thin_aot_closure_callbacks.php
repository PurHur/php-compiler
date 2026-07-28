<?php

/**
 * Repro #24156 — thin standalone AOT Closure callbacks (reduce / usort / array_map).
 *
 * usort uses an explicit compare (not <=>) — AOT variable spaceship is a separate defect.
 * ArrayReduceLlvm avoids NestedJIT RuntimeIndirect free() with ≥3 Closures + ArrayMapLlvm.
 */
echo array_reduce([1, 2, 3], fn($c, $x) => $c + $x, 0), "\n";
$a = [3, 1, 2];
usort($a, fn($x, $y) => $x < $y ? -1 : ($x > $y ? 1 : 0));
echo implode(',', $a), "\n";
$r = array_map(fn($x) => $x * 2, [1, 2, 3]);
echo implode(',', $r), "\n";
