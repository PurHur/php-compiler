--TEST--
String offset assignment beyond length space-pads gap (#10380, zend_operators.c)
--FILE--
<?php
$a = 'abc';
$a[5] = 'x';
echo bin2hex($a), "\n";

$a = 'abc';
$a[3] = 'd';
echo bin2hex($a), "\n";
--EXPECT--
616263202078
61626364
