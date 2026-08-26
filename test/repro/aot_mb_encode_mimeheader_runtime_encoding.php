<?php
// #35225 / #34299 — AOT mb_encode_mimeheader body + charset/transfer parity vs VM
function s(string $x): string { return $x; }
echo mb_encode_mimeheader(s('Hello'), 'UTF-8', 'B'), "\n";
echo mb_encode_mimeheader(s('Hello World'), 'UTF-8', 'B'), "\n";
echo mb_encode_mimeheader(s('über'), 'UTF-8', 'B'), "\n";
echo mb_encode_mimeheader(s('café'), 'UTF-8', 'B'), "\n";
echo mb_encode_mimeheader(s('café'), s('UTF-8'), s('B')), "\n";
echo mb_encode_mimeheader(s('café'), s('UTF-8'), s('Q')), "\n";
echo mb_decode_mimeheader(s('=?UTF-8?B?Y2Fmw6k=?=')), "\n";
