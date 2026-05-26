--TEST--
stdlib krsort() on integer-key associative arrays (issue #2282)
--FILE--
<?php
$data = array(10 => 'a', 30 => 'c', 20 => 'b');
krsort($data);
echo implode(',', array_keys($data)), "\n";
--EXPECT--
30,20,10
