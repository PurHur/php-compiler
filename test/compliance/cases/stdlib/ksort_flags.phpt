--TEST--
stdlib ksort/krsort/asort/arsort optional SORT_* flags (#4118)
--FILE--
<?php
$a = ['b' => 1, 'a' => 2];
ksort($a, SORT_STRING);
echo implode(',', array_keys($a)), "\n";
$b = [3, 1, 2];
asort($b, SORT_NUMERIC);
echo implode(',', $b), "\n";
$c = ['10' => 1, '2' => 2];
ksort($c, SORT_NUMERIC);
echo implode(',', array_keys($c)), "\n";
$d = ['z' => 1, 'a' => 2];
krsort($d, SORT_STRING);
echo implode(',', array_keys($d)), "\n";
$e = [3, 1, 2];
arsort($e, SORT_NUMERIC);
echo implode(',', $e), "\n";
--EXPECT--
a,b
1,2,3
2,10
z,a
3,2,1
