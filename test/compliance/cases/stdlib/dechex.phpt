--TEST--
stdlib dechex() for non-negative integers
--FILE--
<?php
echo dechex(0), "\n";
echo dechex(10), "\n";
echo dechex(255), "\n";
echo dechex(4096), "\n";
--EXPECT--
0
a
ff
1000
