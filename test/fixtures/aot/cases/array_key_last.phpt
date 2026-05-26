--TEST--
AOT: array_key_last()
--FILE--
<?php
$k = array_key_last([]);
echo $k === null ? "empty\n" : "bad\n";
$k = array_key_last([10, 20]);
echo $k, "\n";
$a = ['x' => 1, 'y' => 2];
$k = array_key_last($a);
echo $k, "\n";
--EXPECT--
empty
1
y
