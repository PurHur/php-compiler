--TEST--
JIT: preg_split() limit and PREG_SPLIT_* flags (issue #4078)
--FILE--
<?php
$parts = preg_split('/(\d+)/', 'a1b2c', -1, PREG_SPLIT_DELIM_CAPTURE);
echo count($parts), "\n";
echo $parts[0], '|', $parts[1], '|', $parts[2], '|', $parts[3], '|', $parts[4], "\n";
$trimmed = preg_split('/\s+/', 'a  b', -1, PREG_SPLIT_NO_EMPTY);
echo count($trimmed), "\n";
echo $trimmed[0], '|', $trimmed[1], "\n";
$offset = preg_split('/ /', 'a b', -1, PREG_SPLIT_OFFSET_CAPTURE);
echo $offset[0][0], ':', $offset[0][1], "\n";
echo $offset[1][0], ':', $offset[1][1], "\n";
--EXPECT--
5
a|1|b|2|c
2
a|b
a:0
b:2
