--TEST--
Language: integer subtraction overflow promotes to float on AOT/JIT (#32422)
--FILE--
<?php
var_dump(PHP_INT_MIN - 1);
var_dump(0 - PHP_INT_MIN);
function subov(int $a, int $b): void
{
    var_dump($a - $b);
}
subov(PHP_INT_MIN, 1);
subov(0, PHP_INT_MIN);
var_dump(5 - 3);
?>
--EXPECT--
float(-9.223372036854776E+18)
float(9.223372036854776E+18)
float(-9.223372036854776E+18)
float(9.223372036854776E+18)
int(2)
