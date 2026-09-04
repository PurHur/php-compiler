<?php
// Discarded str_pad/chunk_split/wordwrap/str_split/explode on typed args must match Zend (#36386).
// Side-effect-free statements only — results unused (soft-null coerce kept live elsewhere).
// @differential-repeat: 3
function work(string $s, string $d, int $n, int $loops): int
{
    $c = 0;
    for ($k = 0; $k < $loops; ++$k) {
        str_pad($s, $n, '-');
        chunk_split($s, $n, "\n");
        wordwrap($s, $n, "\n");
        str_split($s, $n);
        explode($d, $s);
        $c += $k;
    }

    return $c
        + strlen(str_pad($s, $n, '-'))
        + strlen(chunk_split($s, $n, ':'))
        + strlen(wordwrap($s, $n, '/'))
        + count(str_split($s, $n))
        + count(explode($d, $s));
}
echo work('abcdef', 'c', 2, 5), "\n";
echo work('hello', 'l', 3, 3), "\n";
echo work('AbCdeF', 'b', 2, 2), "\n";
