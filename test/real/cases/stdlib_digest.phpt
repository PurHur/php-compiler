--TEST--
Digest: str_repeat, strlen, strcmp, intdiv, ord, min, ceil, floor, intval
--FILE--
<?php
$s = str_repeat('a', 3);
echo strlen($s), "\n";
echo strcmp($s, 'aaa'), "\n";
echo strcmp($s, 'bbb'), "\n";
echo intdiv(100, 7), "\n";
echo ord('Z'), "\n";
echo ord(chr(ord('Z'))), "\n";
echo min(ord('a'), ord('z')), "\n";
echo max(abs(-4), min(10, 3)), "\n";
echo intval(ceil(2.1)), "\n";
echo intval(floor(2.9)), "\n";
--EXPECT--
3
0
-1
14
90
90
97
4
3
2
