<?php
// Discarded microtime / hrtime / gettimeofday must match Zend (#36386).
// Side-effect-free statements only. Live shape checks use float forms.
// php-src: ext/standard/microtime.c, ext/standard/hrtime.c
// @differential-repeat: 3
function work(int $loops): int
{
    $c = 0;
    for ($k = 0; $k < $loops; ++$k) {
        microtime();
        microtime(true);
        hrtime();
        hrtime(true);
        gettimeofday();
        gettimeofday(true);
        $c += $k;
    }

    return $c;
}
echo work(5), "\n";
echo work(3), "\n";
echo work(2), "\n";

$ms = microtime(true);
$gt = gettimeofday(true);
echo is_float($ms) && $ms > 0.0 ? "1" : "0", "\n";
echo is_float($gt) && $gt > 0.0 ? "1" : "0", "\n";
