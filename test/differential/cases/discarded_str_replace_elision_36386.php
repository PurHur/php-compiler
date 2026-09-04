<?php
// Discarded str_replace/str_ireplace/substr_replace/strtr on typed strings must match Zend (#36386).
// Side-effect-free statements only — results unused (array forms / &$count kept live elsewhere).
// Live strtr AOT still segfaults on master — discarded-only for strtr; live results for the others.
// @differential-repeat: 3
function work(string $s, string $a, string $b, string $from, string $to, int $off, int $loops): int
{
    $c = 0;
    for ($k = 0; $k < $loops; ++$k) {
        str_replace($a, $b, $s);
        str_ireplace($a, $b, $s);
        substr_replace($s, $b, $off);
        strtr($s, $from, $to);
        $c += $k;
    }

    return $c
        + strlen(str_replace($a, $b, $s))
        + strlen(str_ireplace($a, $b, $s))
        + strlen(substr_replace($s, $b, $off));
}
echo work('abcABC', 'a', 'x', 'ab', 'XY', 1, 5), "\n";
echo work('Hello', 'l', 'L', 'He', 'hE', 2, 3), "\n";
echo work('AbCdeF', 'b', 'B', 'AC', 'ac', 0, 2), "\n";
