--TEST--
AOT: acos()/sinh() inverse and hyperbolic trig (issue #3659)
--FILE--
<?php
echo intval(acos(0.5) * 1000), "\n";
echo intval(asin(0.5) * 1000), "\n";
echo intval(atan(1) * 1000), "\n";
echo sinh(0), "\n";
echo intval(sinh(1) * 1000), "\n";
echo cosh(0), "\n";
echo intval(cosh(1) * 1000), "\n";
echo tanh(0), "\n";
echo intval(tanh(1) * 1000), "\n";
--EXPECT--
1047
523
785
0
1175
1
1543
0
761
--EXPECT_EXIT--
0
