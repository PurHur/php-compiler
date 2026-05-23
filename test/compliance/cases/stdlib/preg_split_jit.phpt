--TEST--
JIT: preg_split() via __compiler_preg_split (issue #1178)
--FILE--
<?php
$parts = preg_split('/,/', 'a,b,c');
echo count($parts), "\n";
echo $parts[0], '|', $parts[1], '|', $parts[2], "\n";
$tail = preg_split('/,/', 'a,b,');
echo count($tail), "\n";
echo $tail[0], '|', $tail[1], '|', $tail[2], "\n";
--EXPECT--
3
a|b|c
3
a|b|
