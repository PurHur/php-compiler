--TEST--
AOT: (array) cast PHI + type-kind — array/null/int/var/ArrayObject copy (#33863)
--FILE--
<?php
echo implode(',', (array) [1, 2]), "\n";
echo implode(',', (array) null), "\n";
echo implode(',', (array) 7), "\n";
$x = [9, 8];
echo implode(',', (array) $x), "\n";
$ao = new ArrayObject([5, 4]);
echo implode(',', (array) $ao->getArrayCopy()), "\n";
--EXPECT--
1,2

7
9,8
5,4
