--TEST--
stdlib log() natural logarithm
--FILE--
<?php
echo log(1), "\n";
echo intval(log(100)), "\n";
echo intval(log(10) * 1000), "\n";
--EXPECT--
0
4
2302
