--TEST--
AOT: hypot() for integers and floats
--FILE--
<?php
echo hypot(3, 4), "\n";
echo hypot(3.0, 4.0), "\n";
echo hypot(0, 5), "\n";
echo hypot(1.5, 2.0), "\n";
--EXPECT--
5
5
5
2.5
--EXPECT_EXIT--
0
