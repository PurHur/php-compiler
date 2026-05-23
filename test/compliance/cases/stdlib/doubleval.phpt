--TEST--
stdlib doubleval() for integers and floats
--FILE--
<?php
echo doubleval(3), "\n";
echo doubleval(-2), "\n";
echo doubleval(1.5), "\n";
echo doubleval(0.0), "\n";
--EXPECT--
3
-2
1.5
0
