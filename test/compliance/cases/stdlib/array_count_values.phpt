--TEST--
stdlib array_count_values()
--FILE--
<?php
$a = array_count_values(array('a', 'b', 'a', 'c', 'b', 'a'));
echo $a['a'], '|', $a['b'], '|', $a['c'], "\n";
$b = array_count_values(array(1, 2, 1, 3, 2, 1));
echo $b[1], '|', $b[2], '|', $b[3], "\n";
$c = array_count_values(array());
echo sizeof($c), "\n";
--EXPECT--
3|2|1
3|2|1
0
