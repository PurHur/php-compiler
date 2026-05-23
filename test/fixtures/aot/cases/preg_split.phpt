--TEST--
AOT: preg_split() splits on delimiter (issue #1178)
--FILE--
<?php
$parts = preg_split('/-/', 'x-y-z');
echo count($parts), "\n";
echo $parts[0], '|', $parts[1], '|', $parts[2], "\n";
--EXPECT--
3
x|y|z
