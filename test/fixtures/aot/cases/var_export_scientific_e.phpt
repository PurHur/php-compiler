--TEST--
AOT: thin var_export scientific floats use uppercase E like Zend (#33901)
--FILE--
<?php
var_export(PHP_INT_MAX + 1);
echo "\n";
var_export(1.0e100);
echo "\n";
var_export(1.0E-10);
echo "\n";
--EXPECT--
9.223372036854776E+18
1.0E+100
1.0E-10
