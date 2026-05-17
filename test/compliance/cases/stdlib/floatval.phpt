--TEST--
stdlib floatval() for integers and floats
--FILE--
<?php
echo floatval(3), "\n";
echo floatval(-2), "\n";
echo floatval(1.5), "\n";
echo floatval(0.0), "\n";
--EXPECT--
3
-2
1.5
0
