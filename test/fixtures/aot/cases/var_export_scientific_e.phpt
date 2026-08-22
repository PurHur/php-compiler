--TEST--
AOT: thin var_export(scientific float) uppercase E like zend_gcvt (#33901)
--FILE--
<?php
echo var_export(PHP_INT_MAX + 1, true), "\n";
echo var_export(1.0e100, true), "\n";
echo var_export(-9.223372036854776e18, true), "\n";
--EXPECT--
9.223372036854776E+18
1.0E+100
-9.223372036854776E+18
