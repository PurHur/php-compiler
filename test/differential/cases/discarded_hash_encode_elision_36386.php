<?php
// Discarded md5/crc32/base64_encode/soundex/metaphone on typed args must match Zend (#36386).
// Side-effect-free statements only — results unused (null soft-coerce kept).
// Live metaphone/soundex omitted: pre-existing AOT wrong-output / crash on some inputs.
// @differential-repeat: 3
function work(string $s, int $loops): int
{
    $c = 0;
    for ($i = 0; $i < $loops; ++$i) {
        md5($s);
        sha1($s);
        crc32($s);
        base64_encode($s);
        soundex($s);
        metaphone($s);
        convert_uuencode($s);
        hebrev($s);
        $c += $i;
    }

    return $c
        + strlen($s)
        + strlen(md5($s))
        + strlen(sha1($s))
        + strlen(base64_encode($s))
        + (int) crc32($s);
}
echo work('Hello!', 5), "\n";
echo work('programming', 3), "\n";
echo work('Euler', 2), "\n";
