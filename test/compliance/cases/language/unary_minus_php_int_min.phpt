--TEST--
Language: unary minus of PHP_INT_MIN promotes to float (zend_operators.c, #28761)
--FILE--
<?php
$r = -PHP_INT_MIN;
echo gettype($r), "\n";
var_export($r);
echo "\n";
$x = PHP_INT_MIN;
$r2 = -$x;
echo gettype($r2), "\n";
var_export($r2);
echo "\n";
$r3 = abs(-PHP_INT_MIN);
echo gettype($r3), "\n";
var_export($r3);
echo "\n";
var_export(-7);
echo "\n";
?>
--EXPECT--
double
9.223372036854776E+18
double
9.223372036854776E+18
double
9.223372036854776E+18
-7
