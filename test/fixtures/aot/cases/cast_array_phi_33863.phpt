--TEST--
AOT: (array) cast on array/null/scalar/ArrayObject — PHI unwrap (#33863, Zend/zend_operators.c convert_to_array)
--FILE--
<?php
echo implode(',', (array) [1, 2]), "\n";
echo implode(',', (array) null), "\n";
echo implode(',', (array) 7), "\n";
$ao = new ArrayObject(['a' => 1, 'b' => 2]);
echo implode(',', $ao->getArrayCopy()), "\n";
echo implode(',', (array) $ao), "\n";
?>
--EXPECT--
1,2

7
1,2
1,2
