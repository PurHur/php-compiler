--TEST--
stdlib mb_encode_numericentity()/mb_decode_numericentity() UTF-8 round-trip (VM)
--FILE--
<?php
echo function_exists('mb_encode_numericentity') ? '1' : '0';
echo function_exists('mb_decode_numericentity') ? '1' : '0';
echo "\n";
$map = [0x80, 0x10FFFF, 0, 0xFFFFFFFF];
$enc = mb_encode_numericentity("\xE2\x82\xAC", $map, 'UTF-8');
echo $enc, "\n";
echo mb_decode_numericentity($enc, $map, 'UTF-8'), "\n";
echo mb_encode_numericentity("\xE2\x82\xAC", $map, 'UTF-8', true), "\n";
try {
    mb_encode_numericentity('a', [1, 2, 3], 'UTF-8');
    echo "no-ve\n";
} catch (ValueError $e) {
    echo "ve\n";
}
--EXPECT--
11
&#8364;
€
&#x20AC;
ve
