--TEST--
Integration: explode, implode, array_push, in_array
--FILE--
<?php
$parts = explode('-', 'one-two');
echo implode('+', $parts), "\n";
$list = array('a');
array_push($list, 'b', 'c');
echo count($list), "\n";
echo in_array('b', $list) ? 'y' : 'n', "\n";
echo in_array('z', $list) ? 'y' : 'n', "\n";
--EXPECT--
one+two
3
y
n
