--TEST--
stdlib preg_split() PREG_SPLIT_OFFSET_CAPTURE (issue #3639)
--FILE--
<?php
$parts = preg_split('/ /', 'a b', -1, PREG_SPLIT_OFFSET_CAPTURE);
echo count($parts), "\n";
echo $parts[0][0], ':', $parts[0][1], "\n";
echo $parts[1][0], ':', $parts[1][1], "\n";
--EXPECT--
2
a:0
b:2
