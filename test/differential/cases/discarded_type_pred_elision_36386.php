<?php
// Discarded is_* / typed-string strlen must match Zend (#36386).
// Side-effect-free statements only — results unused.
// @differential-repeat: 3
function work(mixed $x, string $s, int $n): int
{
    $c = 0;
    for ($i = 0; $i < $n; ++$i) {
        is_int($x);
        is_string($x);
        is_array($x);
        is_null($x);
        is_numeric($x);
        is_bool($x);
        is_float($x);
        is_object($x);
        is_scalar($x);
        is_countable($x);
        strlen($s);
        $c += $i;
    }

    return $c + (is_int($x) ? 1 : 0) + strlen($s);
}
echo work(7, 'xy', 5), "\n";
echo work('a', 'z', 3), "\n";
echo work(null, '', 2), "\n";
