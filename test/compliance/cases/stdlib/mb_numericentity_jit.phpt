--TEST--
stdlib mb_encode_numericentity()/mb_decode_numericentity() JIT compile-time fold
--FILE--
<?php
$map = [0x80, 0x10FFFF, 0, 0xFFFFFFFF];
$enc = mb_encode_numericentity("\xE2\x82\xAC", $map, 'UTF-8');
echo $enc, "\n";
echo mb_decode_numericentity($enc, $map, 'UTF-8'), "\n";
echo mb_encode_numericentity("\xE2\x82\xAC", $map, 'UTF-8', true), "\n";
--EXPECT--
&#8364;
€
&#x20AC;
