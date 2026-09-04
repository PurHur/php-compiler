<?php
// Discarded ucwords/bin2hex/addslashes + pow/fdiv on typed args must match Zend (#36386).
// Side-effect-free statements only — results unused (null soft-coerce kept).
// @differential-repeat: 3
function work(string $s, float $x, float $y, int $loops): int
{
    $c = 0;
    for ($i = 0; $i < $loops; ++$i) {
        ucwords($s);
        addslashes($s);
        stripslashes($s);
        bin2hex($s);
        pow($x, $y);
        fdiv($x, $y);
        $c += $i;
    }

    return $c + strlen($s) + (int) pow($x, 0.0) + (int) fdiv($x, $y);
}
echo work("o'brien", 2.0, 3.0, 5), "\n";
echo work('ab', 8.0, 2.0, 3), "\n";
echo work('x', 1.0, 1.0, 2), "\n";
