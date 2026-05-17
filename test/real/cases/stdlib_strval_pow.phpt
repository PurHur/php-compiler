--TEST--
Integration: strval, pow, and strlen
--FILE--
<?php
echo strlen(strval(pow(2, 3))), "\n";
echo strval(pow(5, 2)), "\n";
echo strval(intval(pow(9, 0.5))), "\n";
echo boolval(strlen(strval(null))) ? 'y' : 'n', "\n";
--EXPECT--
1
25
3
n
