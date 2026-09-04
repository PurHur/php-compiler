<?php
// Discarded urlencode/str_rot13/quotemeta on typed args must match Zend (#36386).
// Side-effect-free statements only — results unused (null soft-coerce kept).
// @differential-repeat: 3
function work(string $s, int $loops): int
{
    $c = 0;
    for ($i = 0; $i < $loops; ++$i) {
        urlencode($s);
        rawurlencode($s);
        urldecode($s);
        rawurldecode($s);
        str_rot13($s);
        quotemeta($s);
        $c += $i;
    }

    return $c + strlen($s) + strlen(urlencode($s)) + strlen(str_rot13($s));
}
echo work('a b.c', 5), "\n";
echo work('xy', 3), "\n";
echo work('Hello!', 2), "\n";
