--TEST--
AOT: sin/cos/tan-family named num: argument (#23506)
--FILE--
<?php
echo (int) round(sin(num: M_PI / 2)), "\n";
echo (int) round(cos(num: 0)), "\n";
echo (int) round(tan(num: 0)), "\n";
echo (int) asin(num: 0), "\n";
echo (int) acos(num: 1), "\n";
echo (int) atan(num: 0), "\n";
echo (int) exp(num: 0), "\n";
echo (int) sinh(num: 0), "\n";
echo (int) cosh(num: 0), "\n";
echo (int) tanh(num: 0), "\n";
--EXPECT--
1
1
0
0
0
0
1
0
1
0
