--TEST--
Nested array dim in echo ternary after prior ternary on same var (#24017)
--FILE--
<?php
$t = [10, [291, 'echo', 1], 20];
echo is_array($t) ? count($t) : 0, "\n";
echo is_array($t[1]) ? $t[1][0] : 'str', "\n";
$c = is_array($t) ? count($t) : 0;
echo is_array($t[1]) ? $t[1][0] : 'str', "\n";
--EXPECT--
3
291
291
