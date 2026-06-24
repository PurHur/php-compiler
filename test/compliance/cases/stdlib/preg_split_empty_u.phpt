--TEST--
stdlib preg_split() empty //u delimiter (#10967)
--FILE--
<?php
$parts = preg_split('//u', 'abc');
echo count($parts), "\n";
echo $parts[0], '|', $parts[1], '|', $parts[2], '|', $parts[3], '|', $parts[4], "\n";
$trimmed = preg_split('//u', 'abc', -1, PREG_SPLIT_NO_EMPTY);
echo count($trimmed), "\n";
echo $trimmed[0], '|', $trimmed[1], '|', $trimmed[2], "\n";
--EXPECT--
5
|a|b|c|
3
a|b|c
