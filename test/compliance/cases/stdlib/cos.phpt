--TEST--
stdlib cos()
--FILE--
<?php
echo cos(0), "\n";
echo intval(cos(deg2rad(60)) * 1000), "\n";
echo intval(cos(deg2rad(180)) * 1000), "\n";
--EXPECT--
1
500
-1000
