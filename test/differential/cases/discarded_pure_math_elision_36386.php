<?php
// Discarded math.c calls on typed numerics must match Zend (#36386).
// Side-effect-free statements only — results unused (null soft-coerce kept).
// @differential-repeat: 3
function work(int $n, float $x, int $loops): int
{
    $c = 0;
    for ($i = 0; $i < $loops; ++$i) {
        abs($n);
        sqrt($x);
        floor($x);
        ceil($x);
        sin($x);
        cos($x);
        tan($x);
        deg2rad($x);
        rad2deg($x);
        $c += $i;
    }

    return $c + (int) abs($n) + (int) floor($x);
}
echo work(-4, 9.25, 5), "\n";
echo work(0, 0.0, 3), "\n";
echo work(2, 1.0, 2), "\n";
