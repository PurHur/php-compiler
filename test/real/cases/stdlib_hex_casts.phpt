--TEST--
Integration: dechex, hexdec, and bool casts
--FILE--
<?php
echo dechex(hexdec('ff')), "\n";
echo intval(true) + hexdec('a'), "\n";
echo floatval(false) + floatval(true), "\n";
echo strlen(dechex(255)), "\n";
--EXPECT--
ff
11
1
2
