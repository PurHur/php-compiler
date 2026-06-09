--TEST--
stdlib round() precision — Z_PARAM_LONG float/string coercion (issue #4213, ext/standard/math.c)
--FILE--
<?php
echo round(1.5, 0.9), "\n";
echo round(1.5, '1'), "\n";
echo round(1.5, 1.9), "\n";
echo round(1.5, '1', PHP_ROUND_HALF_DOWN), "\n";
--EXPECT--
2
1.5
1.5
1.5
