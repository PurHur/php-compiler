<?php
// Issue #34299 repro — runtime strings (non-foldable) for mimeheader NestedJIT
$s = 'Hello 世界';
echo mb_encode_mimeheader($s, 'UTF-8'), "\n";
$e = mb_encode_mimeheader('Hello 世界', 'UTF-8');
$encoded = $e;
echo mb_decode_mimeheader($encoded), "\n";
