--TEST--
AOT: PHP_ROUND_CEILING/FLOOR/… withheld on PROFILE=8.2 (#22785)
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
echo defined('PHP_ROUND_HALF_UP') ? '1' : '0', "\n";
echo defined('PHP_ROUND_CEILING') ? '1' : '0', "\n";
echo defined('PHP_ROUND_FLOOR') ? '1' : '0', "\n";
echo defined('PHP_ROUND_TOWARD_ZERO') ? '1' : '0', "\n";
echo defined('PHP_ROUND_AWAY_FROM_ZERO') ? '1' : '0', "\n";
?>
--EXPECT--
1
0
0
0
0
