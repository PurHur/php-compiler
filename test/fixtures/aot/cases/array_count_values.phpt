--TEST--
AOT: array_count_values() string and int values
--FILE--
<?php
$a = array_count_values(array('foo', 'bar', 'foo'));
echo $a['foo'], '|', $a['bar'], "\n";
$b = array_count_values(array(10, 20, 10));
echo $b[10], '|', $b[20], "\n";
--EXPECT--
2|1
2|1
