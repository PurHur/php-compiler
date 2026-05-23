--TEST--
AOT: array_shift() on array_flip(array_combine()) string-key arrays
--FILE--
<?php
$f = array_flip(array_combine(['k1', 'k2'], ['v1', 'v2']));
$first = array_shift($f);
array_unshift($f, 'head');
echo count($f), "\n";
echo $first, "\n";
echo $f[0], "\n";
--EXPECT--
2
k1
head
