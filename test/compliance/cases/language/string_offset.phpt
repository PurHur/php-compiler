--TEST--
String offset read and assignment (ASCII bytes)
--FILE--
<?php
$s = 'abc';
echo $s[0], $s[1], $s[2], "\n";
$s[1] = 'z';
echo $s, "\n";
$s[0] = 65;
echo $s, "\n";
--EXPECT--
abc
azc
Azc
