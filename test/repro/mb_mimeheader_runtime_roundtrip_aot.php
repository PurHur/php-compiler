<?php
// AOT NestedJIT mimeheader encode→decode roundtrip — non-foldable (#34310)
$parts = ['Hello ', '世界'];
$s = $parts[0] . $parts[1];
$enc = mb_encode_mimeheader($s);
echo $enc, "\n";
echo mb_decode_mimeheader($enc), "\n";
$tParts = ['ü', 'ber'];
$t = $tParts[0] . $tParts[1];
$e2 = mb_encode_mimeheader($t, 'UTF-8');
echo $e2, "\n";
echo mb_decode_mimeheader($e2), "\n";
