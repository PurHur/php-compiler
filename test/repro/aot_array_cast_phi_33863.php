<?php
// AOT: (array) cast must verify + match Zend for array/null/scalar/ArrayObject copy (#33863)
echo implode(',', (array) [1, 2]), "\n";
echo implode(',', (array) null), "\n";
echo implode(',', (array) 7), "\n";
$x = [9, 8];
echo implode(',', (array) $x), "\n";
$ao = new ArrayObject([5, 4]);
echo implode(',', (array) $ao->getArrayCopy()), "\n";
