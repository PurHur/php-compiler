--TEST--
AOT: array_first_key() / array_last_key()
--FILE--
<?php
$k = array_first_key([]);
echo $k === null ? "empty_first\n" : "bad_first\n";
$k = array_last_key([]);
echo $k === null ? "empty_last\n" : "bad_last\n";
$list = [10, 20, 30];
echo array_first_key($list), "\n";
echo array_last_key($list), "\n";
$a = ['x' => 1, 'y' => 2];
echo array_first_key($a), "\n";
echo array_last_key($a), "\n";
--EXPECT--
empty_first
empty_last
0
2
x
y
