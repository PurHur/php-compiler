--TEST--
stdlib array_multisort() single-array form JIT (#4945, ext/standard/array.c)
--FILE--
<?php
$a = array();
$a[] = 3;
$a[] = 1;
$a[] = 2;
array_multisort($a);
echo implode(',', $a), "\n";
$b = array();
$b[] = 3;
$b[] = 1;
$b[] = 2;
array_multisort($b, SORT_DESC);
echo implode(',', $b), "\n";
--EXPECT--
1,2,3
3,2,1
