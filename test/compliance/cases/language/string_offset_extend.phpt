--TEST--
String offset write extends and space-pads gap like Zend (#10380, zend_operators.c)
--FILE--
<?php
$s = 'a';
$s[1] = 'b';
echo bin2hex($s), "\n";

$s = 'ab';
$s[5] = 'x';
echo bin2hex($s), "\n";
--EXPECT--
6162
616220202078
