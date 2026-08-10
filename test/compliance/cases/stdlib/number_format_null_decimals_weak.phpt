--TEST--
stdlib number_format() — null $decimals coerces to 0 without strict_types (#29764, ext/standard/number_format.c)
--FILE--
<?php
echo number_format(1.5, null), "\n";
--EXPECT--
2
