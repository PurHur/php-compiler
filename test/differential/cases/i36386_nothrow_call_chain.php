<?php
declare(strict_types=1);
/** @differential-repeat: 3 */
function mid(int $n): int { return leaf($n) + leaf($n + 1); }
function leaf(int $n): int { return $n + 1; }
function top(int $n): int {
    $s = 0;
    for ($i = 0; $i < $n; ++$i) {
        $s += mid($i);
    }
    return $s;
}
echo top(20), "\n";
