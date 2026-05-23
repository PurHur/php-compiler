--TEST--
AOT: floatval() strings and null
--FILE--
<?php
echo floatval('3.14'), "\n";
echo floatval('0'), "\n";
echo floatval(null), "\n";
--EXPECT--
3.14
0
0
