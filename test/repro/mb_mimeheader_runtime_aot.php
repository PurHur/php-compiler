<?php
// AOT NestedJIT mb_encode/decode_mimeheader runtime (#34299)
$s = 'Hello 世界';
echo mb_encode_mimeheader($s, 'UTF-8'), "\n";
$e = 'Hello =?UTF-8?B?5LiW55WM?=';
echo mb_decode_mimeheader($e), "\n";
$t = 'über';
echo mb_encode_mimeheader($t, 'UTF-8', 'B'), "\n";
$q = 'Café';
echo mb_encode_mimeheader($q, 'UTF-8', 'Q'), "\n";
$d = '=?UTF-8?Q?Caf=C3=A9?=';
echo mb_decode_mimeheader($d), "\n";
