--TEST--
Language: integer + / * overflow promotes to float on AOT/JIT (#31964)
--FILE--
<?php
var_dump(PHP_INT_MAX + 1);
var_dump(PHP_INT_MAX * 2);
?>
--EXPECT--
float(9.223372036854776E+18)
float(1.8446744073709552E+19)
