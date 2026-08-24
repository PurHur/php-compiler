<?php
// AOT NestedJIT mb_encode/decode_mimeheader runtime (#34299 leftover of #6038)
$s = 'Hello 世界';
$enc = mb_encode_mimeheader($s, 'UTF-8');
echo $enc, "\n";
$e = $enc;
echo mb_decode_mimeheader($e), "\n";
$t = 'über';
echo mb_encode_mimeheader($t, 'UTF-8', 'B'), "\n";
$q = 'Café';
echo mb_encode_mimeheader($q, 'UTF-8', 'Q'), "\n";
