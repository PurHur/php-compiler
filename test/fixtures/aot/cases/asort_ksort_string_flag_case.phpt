--TEST--
AOT: asort/arsort/ksort/krsort SORT_STRING|SORT_FLAG_CASE (#34707)
--FILE--
<?php
$a = array('x' => 'B', 'y' => 'a', 'z' => 'C');
asort($a, SORT_STRING | SORT_FLAG_CASE);
echo implode(',', array_values($a)), "\n";
$b = array('x' => 'B', 'y' => 'a', 'z' => 'C');
arsort($b, SORT_STRING | SORT_FLAG_CASE);
echo implode(',', array_values($b)), "\n";
$c = array('B' => 1, 'a' => 2, 'C' => 3);
ksort($c, SORT_STRING | SORT_FLAG_CASE);
echo implode(',', array_keys($c)), "\n";
$d = array('B' => 1, 'a' => 2, 'C' => 3);
krsort($d, SORT_STRING | SORT_FLAG_CASE);
echo implode(',', array_keys($d)), "\n";
--EXPECT--
a,B,C
C,B,a
a,B,C
C,B,a
