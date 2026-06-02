--TEST--
stdlib preg_split() PREG_SPLIT_DELIM_CAPTURE (issue #3639)
--FILE--
<?php
$parts = preg_split('/(a+)/', 'xax', -1, PREG_SPLIT_DELIM_CAPTURE);
echo count($parts), "\n";
echo $parts[0], '|', $parts[1], '|', $parts[2], "\n";
$trimmed = preg_split('/\s+/', 'a  b', -1, PREG_SPLIT_NO_EMPTY);
echo count($trimmed), "\n";
echo $trimmed[0], '|', $trimmed[1], "\n";
--EXPECT--
3
x|a|x
2
a|b
