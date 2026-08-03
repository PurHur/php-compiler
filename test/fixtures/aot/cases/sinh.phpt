--TEST--
AOT: sinh() float return (#27125)
--FILE--
<?php
echo round(sinh(1), 6), "\n";
--EXPECT--
1.175201
--EXPECT_EXIT--
0
