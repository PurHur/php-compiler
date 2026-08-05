--TEST--
AOT: number_format() negative $decimals round on PHP 8.4+ profile (#27899)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo number_format(1234.5678, -1), "\n";
echo number_format(12.345, -1), "\n";
--EXPECT--
1,230
10
