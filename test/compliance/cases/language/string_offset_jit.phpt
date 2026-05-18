--TEST--
String offset read and assignment under JIT
--FILE--
<?php
$s = 'abc';
echo $s[0], $s[1], $s[2], "\n";
$s[1] = 'z';
echo $s, "\n";
--EXPECT--
abc
azc
