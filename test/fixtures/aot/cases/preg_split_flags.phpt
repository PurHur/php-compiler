--TEST--
AOT: preg_split() limit and PREG_SPLIT_DELIM_CAPTURE (issue #4078)
--FILE--
<?php
$parts = preg_split('/(\d+)/', 'a1b2c', -1, PREG_SPLIT_DELIM_CAPTURE);
echo count($parts), "\n";
echo $parts[0], '|', $parts[1], '|', $parts[2], '|', $parts[3], '|', $parts[4], "\n";
--EXPECT--
5
a|1|b|2|c
