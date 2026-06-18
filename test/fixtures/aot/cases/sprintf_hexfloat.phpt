--TEST--
AOT: sprintf() %a/%A hex-float (#9059)
--FILE--
<?php
echo sprintf('%a', 3.14159), "\n";
echo sprintf('%A', 3.14159), "\n";
--EXPECT--
0x1.921f9f01b866ep+1
0X1.921F9F01B866EP+1
