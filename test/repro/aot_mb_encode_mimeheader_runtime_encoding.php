<?php
// #35225 — AOT mb_encode_mimeheader runtime charset/transfer + non-foldable $str
// php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_encode_mimeheader)
function enc(string $s): string
{
    return $s;
}

function s(string $x): string
{
    return $x;
}

echo mb_encode_mimeheader('café', enc('UTF-8'), enc('B')), "\n";
echo mb_encode_mimeheader(s('café'), 'UTF-8', 'B'), "\n";
$parts = ['Hello ', '世界'];
$s = $parts[0].$parts[1];
$enc = mb_encode_mimeheader($s);
echo $enc, "\n";
echo mb_decode_mimeheader($enc), "\n";
