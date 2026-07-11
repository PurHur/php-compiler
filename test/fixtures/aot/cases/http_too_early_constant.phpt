--TEST--
AOT HTTP_TOO_EARLY constant — forward PHP 8.4 profile (#18059)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo defined('HTTP_TOO_EARLY') ? '1' : '0';
echo "\n";
echo HTTP_TOO_EARLY;
--EXPECT--
1
425
