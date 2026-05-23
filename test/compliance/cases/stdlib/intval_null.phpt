--TEST--
stdlib intval() for null and strings
--FILE--
<?php
echo intval(null), "\n";
echo intval('42'), "\n";
echo intval('0'), "\n";
echo intval(''), "\n";
echo intval('9.9'), "\n";
--EXPECT--
0
42
0
0
9
