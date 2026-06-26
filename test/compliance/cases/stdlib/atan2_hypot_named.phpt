--TEST--
stdlib atan2()/hypot() y:/x: named parameters (#12101, ext/standard/math.c)
--FILE--
<?php
echo atan2(y: 1, x: 1), "\n";
echo hypot(x: 3, y: 4), "\n";
echo atan2(1, 1), "\n";
echo hypot(3, 4), "\n";
--EXPECT--
0.78539816339745
5
0.78539816339745
5
