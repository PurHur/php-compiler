--TEST--
AOT: pow() for integers and floats
--FILE--
<?php
echo pow(2, 3), "\n";
echo pow(10, 2), "\n";
echo pow(9, 0.5), "\n";
--EXPECT--
8
100
3
--EXPECT_EXIT--
0
