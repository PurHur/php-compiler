--TEST--
stdlib array_values()
--FILE--
<?php
$a = array('x', 'y');
$b = array_values($a);
echo count($b), "\n";
echo in_array('x', $b) ? 'y' : 'n', "\n";
echo in_array('y', $b) ? 'y' : 'n', "\n";
--EXPECT--
2
y
y
