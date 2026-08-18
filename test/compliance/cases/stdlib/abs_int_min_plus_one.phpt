--TEST--
stdlib abs() — PHP_INT_MIN + 1 stays int (ext/standard/math.c, #32309)
--FILE--
<?php
var_dump(abs(PHP_INT_MIN + 1));
var_dump(abs(-7));
?>
--EXPECT--
int(9223372036854775807)
int(7)
