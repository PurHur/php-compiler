--TEST--
AOT: preg_split() zero-width / empty-capture patterns (#14902)
--FILE--
<?php
$parts = preg_split('/()/', 'ab');
echo count($parts), "\n";
echo $parts[0], '|', $parts[1], '|', $parts[2], '|', $parts[3], "\n";
--EXPECT--
4
|a|b|
