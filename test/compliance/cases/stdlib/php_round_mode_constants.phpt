--TEST--
PHP 8.4 PHP_ROUND_CEILING/FLOOR/TOWARD_ZERO/AWAY_FROM_ZERO constants (issue #11730)
--FILE--
<?php
echo defined('PHP_ROUND_CEILING') ? '1' : '0', "\n";
echo defined('PHP_ROUND_FLOOR') ? '1' : '0', "\n";
echo defined('PHP_ROUND_TOWARD_ZERO') ? '1' : '0', "\n";
echo defined('PHP_ROUND_AWAY_FROM_ZERO') ? '1' : '0', "\n";
echo PHP_ROUND_CEILING, "\n";
echo PHP_ROUND_FLOOR, "\n";
echo PHP_ROUND_TOWARD_ZERO, "\n";
echo PHP_ROUND_AWAY_FROM_ZERO, "\n";
echo round(2.5, 0, PHP_ROUND_CEILING), "\n";
echo round(-2.5, 0, PHP_ROUND_FLOOR), "\n";
echo round(2.7, 0, PHP_ROUND_TOWARD_ZERO), "\n";
echo round(2.3, 0, PHP_ROUND_AWAY_FROM_ZERO), "\n";
?>
--EXPECT--
1
1
1
1
5
6
7
8
3
-3
2
3
