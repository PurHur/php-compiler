--TEST--
Language: integer subtraction overflow promotes to float on AOT/JIT (#32422)
--FILE--
<?php
var_dump(PHP_INT_MIN - 1);
$a = PHP_INT_MIN;
var_dump($a - 1);
var_dump(0 - PHP_INT_MIN);
var_dump(10 - 3);
?>
--EXPECT--
float(-9.223372036854776E+18)
float(-9.223372036854776E+18)
float(9.223372036854776E+18)
int(7)
