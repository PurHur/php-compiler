--TEST--
stdlib intval() for strings and null
--FILE--
<?php
echo intval('42'), "\n";
echo intval('0'), "\n";
echo intval(''), "\n";
echo intval(null), "\n";
echo intval('9.9'), "\n";
--EXPECT--
42
0
0
0
9
