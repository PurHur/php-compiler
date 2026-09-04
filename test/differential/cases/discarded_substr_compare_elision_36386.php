<?php
// Discarded substr/str_repeat/strcmp/strpos/strstr on typed args must match Zend (#36386).
// Side-effect-free statements only — results unused (null soft-coerce / int needles kept).
// @differential-repeat: 3
function work(string $s, string $n, int $i, int $loops): int
{
    $c = 0;
    for ($k = 0; $k < $loops; ++$k) {
        substr($s, $i);
        substr($s, $i, 1);
        str_repeat($s, $i);
        strcmp($s, $n);
        strcasecmp($s, $n);
        strpos($s, $n);
        stripos($s, $n);
        strstr($s, $n);
        $c += $k;
    }

    return $c
        + strlen($s)
        + strlen((string) substr($s, 0, 1))
        + strcmp($s, $n)
        + (int) strpos($s, $n);
}
echo work('Hello', 'e', 1, 5), "\n";
echo work('Programming', 'g', 2, 3), "\n";
echo work('AbC', 'b', 0, 2), "\n";
