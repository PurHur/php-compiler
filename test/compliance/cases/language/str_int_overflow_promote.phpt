--TEST--
Language: numeric-string integer overflow promotes to float on AOT/JIT (#32426)
--FILE--
<?php
var_dump("9223372036854775807" + 1);
var_dump(1 + "9223372036854775807");
var_dump("9223372036854775807" + "1");
var_dump("9223372036854775807" * 2);
$s = "9223372036854775807";
$n = 1;
var_dump($s + $n);
var_dump("10" + 3);
?>
--EXPECT--
float(9.223372036854776E+18)
float(9.223372036854776E+18)
float(9.223372036854776E+18)
float(1.8446744073709552E+19)
float(9.223372036854776E+18)
int(13)
