--TEST--
stdlib in_array() for scalar haystacks
--FILE--
<?php
$a = array(1, 2, 'x');
echo in_array(2, $a) ? 'y' : 'n', "\n";
echo in_array('2', $a) ? 'y' : 'n', "\n";
echo in_array('2', $a, true) ? 'y' : 'n', "\n";
echo in_array('y', $a) ? 'y' : 'n', "\n";
--EXPECT--
y
y
n
n
