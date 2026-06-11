<?php

declare(strict_types=1);

$map = [0x80, 0x10FFFF, 0, 0xFFFFFFFF];
echo 'encode: ', function_exists('mb_encode_numericentity') ? 'yes' : 'no', "\n";
echo 'decode: ', function_exists('mb_decode_numericentity') ? 'yes' : 'no', "\n";
$enc = mb_encode_numericentity("\xE2\x82\xAC", $map, 'UTF-8');
echo $enc, "\n";
echo mb_decode_numericentity($enc, $map, 'UTF-8'), "\n";
