--TEST--
stdlib number_format() negative $decimals significant-digit rounding on PHP 8.3+ profile (#17261, ext/standard/math.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo number_format(1234.5678, -1), "\n";
echo number_format(12.345, -1), "\n";
echo number_format(1.5, -1), "\n";
--EXPECT--
1,230
10
0
