--TEST--
AOT: hypot() for integers and floats
--FILE--
<?php
echo hypot(3, 4), "\n";
echo hypot(6, 8), "\n";
echo hypot(5, 12), "\n";
--EXPECT--
5
10
13
--EXPECT_EXIT--
0
