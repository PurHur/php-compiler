--TEST--
stdlib abs() — PHP_INT_MIN promotes to float (ext/standard/math.c, #5238)
--FILE--
<?php
var_export(abs(PHP_INT_MIN));
echo "\n";
var_export(abs(-7));
echo "\n";
?>
--EXPECT--
9.2233720368548E+18
7
