<?php
// Issue #34299 repro — runtime strings (non-foldable) for mimeheader NestedJIT
// Encode and decode are separate: NestedJIT→NestedJIT round-trip segfaults
// (same as mb_convert_kana peer #34294).
$s = 'Hello 世界';
echo mb_encode_mimeheader($s, 'UTF-8'), "\n";
$e = 'Hello =?UTF-8?B?5LiW55WM?=';
echo mb_decode_mimeheader($e), "\n";
