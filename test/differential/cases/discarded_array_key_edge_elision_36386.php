<?php
// Discarded array_key_first / array_key_last / array_is_list on typed arrays (#36386).
// Live shape checks keep results. php-src: ext/standard/array.c
// @differential-repeat: 3
function work(int $loops): int
{
    $a = [1, 2, 3];
    $c = 0;
    for ($k = 0; $k < $loops; ++$k) {
        array_key_first($a);
        array_key_last($a);
        array_is_list($a);
        $c += $k;
    }

    return $c;
}
echo work(5), "\n";
echo work(3), "\n";
echo work(2), "\n";

$a = ['x' => 1, 'y' => 2];
$b = [10, 20, 30];
echo (string) array_key_first($a), "\n";
echo (string) array_key_last($a), "\n";
echo array_is_list($a) ? "1" : "0", "\n";
echo array_is_list($b) ? "1" : "0", "\n";
echo (string) array_key_first($b), "\n";
echo (string) array_key_last($b), "\n";
