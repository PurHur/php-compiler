--TEST--
AOT: deg2rad-family named num: argument (#27795)
--FILE--
<?php
echo (int) round(rad2deg(deg2rad(num: 180))), "\n";
echo (int) round(rad2deg(num: M_PI)), "\n";
echo (int) expm1(num: 0), "\n";
echo (int) log1p(num: 0), "\n";
echo (int) asinh(num: 0), "\n";
echo (int) acosh(num: 1), "\n";
echo (int) atanh(num: 0), "\n";
--EXPECT--
180
180
0
0
0
0
0
