--TEST--
stdlib number_format() negative $decimals round-then-MAX(0,dec) on PHP 8.4+ profile (#27899, php-src math.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo number_format(1234.5678, -1), "\n";
echo number_format(1234.5678, -2), "\n";
echo number_format(12.345, -1), "\n";
--EXPECT--
1,230
1,200
10
