--TEST--
AOT round() precision and PHP_ROUND_* mode (issue #3522)
--FILE--
<?php
echo round(2.5, 0, PHP_ROUND_HALF_UP), "\n";
echo round(1.5, 2), "\n";
echo round(2.5, 0, PHP_ROUND_HALF_EVEN), "\n";
echo PHP_ROUND_HALF_UP, "\n";
--EXPECT--
3
1.5
2
1
