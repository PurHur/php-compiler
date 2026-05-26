--TEST--
JIT: array_count_values()
--FILE--
<?php
$a = array_count_values(array('x', 'y', 'x'));
echo $a['x'], '|', $a['y'], "\n";
$b = array_count_values(array(5, 5, 7));
echo $b[5], '|', $b[7], "\n";
--EXPECT--
2|1
2|1
