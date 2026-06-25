--TEST--
AOT: PHP 8.4 PHP_ROUND_* mode constants (issue #11730)
--FILE--
<?php
echo defined('PHP_ROUND_CEILING') ? '1' : '0', "\n";
echo PHP_ROUND_CEILING, "\n";
echo round(2.5, 0, PHP_ROUND_CEILING), "\n";
echo round(-2.5, 0, PHP_ROUND_FLOOR), "\n";
?>
--EXPECT--
1
5
3
-3
