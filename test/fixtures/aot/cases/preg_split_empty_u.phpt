--TEST--
AOT: preg_split() empty //u delimiter (#10967)
--FILE--
<?php
$parts = preg_split('//u', 'abc', -1, PREG_SPLIT_NO_EMPTY);
echo count($parts), "\n";
echo $parts[0], '|', $parts[1], '|', $parts[2], "\n";
--EXPECT--
3
a|b|c
