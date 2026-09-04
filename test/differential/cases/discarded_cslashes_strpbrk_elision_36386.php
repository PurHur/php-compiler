<?php
// Discarded addcslashes/stripcslashes/strpbrk on typed strings must match Zend (#36386).
// Side-effect-free statements only — results unused.
// Live addcslashes/stripcslashes AOT still segfault on master — discarded-only for those;
// live result checked for strpbrk only.
// @differential-repeat: 3
function work(string $s, string $chars, int $loops): int
{
    $c = 0;
    for ($k = 0; $k < $loops; ++$k) {
        addcslashes($s, $chars);
        stripcslashes($s);
        strpbrk($s, $chars);
        $c += $k;
    }
    $found = strpbrk($s, $chars);

    return $c + (false === $found ? 0 : strlen($found));
}
echo work("a\nb", 'A..z'."\n", 5), "\n";
echo work('Hello', 'el', 3), "\n";
echo work('xyz', 'a', 2), "\n";
