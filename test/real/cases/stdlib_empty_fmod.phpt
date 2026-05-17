--TEST--
Integration: empty, fmod, min/max floats, and boolval
--FILE--
<?php
echo empty(boolval(0)) ? 'y' : 'n', "\n";
echo intval(fmod(10, 3)), "\n";
echo strval(min(2.5, 3)), "\n";
echo empty(max(0, 1)) ? 'y' : 'n', "\n";
--EXPECT--
y
1
2.5
n
