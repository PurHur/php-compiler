--TEST--
stdlib floatval() for strings and null
--FILE--
<?php
echo floatval('3.14'), "\n";
echo floatval('0'), "\n";
echo floatval(''), "\n";
echo floatval(null), "\n";
--EXPECT--
3.14
0
0
0
