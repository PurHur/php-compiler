--TEST--
stdlib sprintf() %a/%A hex-float conversions (#9059, ext/standard/sprintf.c)
--FILE--
<?php
echo sprintf('%a', 3.14159), "\n";
echo sprintf('%A', 3.14159), "\n";
echo sprintf('%.6a', 3.14159), "\n";
echo sprintf('%a', 0.0), "\n";
echo sprintf('%a', -1.5), "\n";
--EXPECT--
0x1.921f9f01b866ep+1
0X1.921F9F01B866EP+1
0x1.921f9fp+1
0x0p+0
-0x1.8p+0
