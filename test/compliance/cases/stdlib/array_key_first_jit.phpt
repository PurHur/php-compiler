--TEST--
JIT: array_key_first()
--FILE--
<?php
$k = array_key_first([]);
echo $k === null ? "empty\n" : "bad\n";
$k = array_key_first([10, 20]);
echo $k, "\n";
$a = ['x' => 1, 'y' => 2];
$k = array_key_first($a);
echo $k, "\n";
--EXPECT--
empty
0
x
