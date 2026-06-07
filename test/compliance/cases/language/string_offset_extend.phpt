--TEST--
String offset write extends and NUL-pads like Zend (#5353, #7430)
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
616200000078
