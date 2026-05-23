--TEST--
AOT: floatval() strings and null
--FILE--
<?php
echo floatval('42'), "\n";
echo floatval('1.5'), "\n";
echo floatval(null), "\n";
--EXPECT--
42
1.5
0
