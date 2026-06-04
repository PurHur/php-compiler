--TEST--
String offset write extends and space-pads like Zend (#5353)
--FILE--
<?php
$s = 'a';
$s[1] = 'b';
var_export($s);
echo "\n";

$s = 'ab';
$s[5] = 'x';
var_export($s);
echo "\n";
--EXPECT--
'ab'
'ab   x'
