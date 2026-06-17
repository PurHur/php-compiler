--TEST--
AOT: acosh()/asinh()/atanh() inverse hyperbolic (issue #9220)
--FILE--
<?php
echo function_exists('acosh') ? '1' : '0', "\n";
echo function_exists('asinh') ? '1' : '0', "\n";
echo function_exists('atanh') ? '1' : '0', "\n";
echo intval(acosh(1.5) * 1000), "\n";
echo intval(asinh(1.5) * 1000), "\n";
echo intval(atanh(0.5) * 1000), "\n";
--EXPECT--
1
1
1
962
1194
549
--EXPECT_EXIT--
0
