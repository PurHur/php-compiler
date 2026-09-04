<?php
// Discarded ord()/chr() on typed args must match Zend (#36386).
// Side-effect-free statements only — results unused (null soft-coerce kept).
// @differential-repeat: 3
function work(string $s, int $n, int $loops): int
{
    $c = 0;
    for ($i = 0; $i < $loops; ++$i) {
        ord($s);
        chr($n);
        $c += $i;
    }

    return $c + ord($s) + strlen(chr($n));
}
echo work('A', 65, 5), "\n";
echo work('z', 122, 3), "\n";
echo work('', 0, 2), "\n";
