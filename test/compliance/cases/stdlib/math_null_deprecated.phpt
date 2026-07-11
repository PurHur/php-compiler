--TEST--
stdlib abs/round/ceil/floor — null operand E_DEPRECATED then 0 (#16410, ext/standard/math.c)
--FILE--
<?php
error_reporting(E_ALL);
echo abs(null), "\n";
echo round(null), "\n";
echo ceil(null), "\n";
echo floor(null), "\n";
--EXPECTF--
PHP Deprecated:  abs(): Passing null to parameter #1 ($num) of type int|float is deprecated in %s on line %d
PHP Deprecated:  round(): Passing null to parameter #1 ($num) of type int|float is deprecated in %s on line %d
PHP Deprecated:  ceil(): Passing null to parameter #1 ($num) of type int|float is deprecated in %s on line %d
PHP Deprecated:  floor(): Passing null to parameter #1 ($num) of type int|float is deprecated in %s on line %d
0
0
0
0
