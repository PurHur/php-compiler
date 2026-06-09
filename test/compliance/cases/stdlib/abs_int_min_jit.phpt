--TEST--
stdlib abs() JIT — PHP_INT_MIN promotes to float (#5238)
--FILE--
<?php
var_export(abs(PHP_INT_MIN));
echo "\n";
?>
--EXPECT--
9.2233720368548E+18
