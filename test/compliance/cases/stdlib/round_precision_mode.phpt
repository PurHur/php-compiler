--TEST--
stdlib round() precision and PHP_ROUND_* mode (issue #3522)
--FILE--
<?php
echo round(2.5, 0, PHP_ROUND_HALF_UP), "\n";
echo round(1.5, 2), "\n";
echo round(2.5, 0, PHP_ROUND_HALF_DOWN), "\n";
echo round(2.5, 0, PHP_ROUND_HALF_EVEN), "\n";
echo round(3.5, 0, PHP_ROUND_HALF_EVEN), "\n";
echo round(2.5, 0, PHP_ROUND_HALF_ODD), "\n";
echo PHP_ROUND_HALF_UP, "\n";
echo PHP_ROUND_HALF_EVEN, "\n";
--EXPECT--
3
1.5
2
2
4
3
1
3
