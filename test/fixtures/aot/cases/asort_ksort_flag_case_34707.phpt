--TEST--
AOT: asort()/arsort()/ksort()/krsort() honor SORT_STRING|SORT_FLAG_CASE (#34707, peer #34702)
--FILE--
<?php
$a = ['x' => 'B', 'y' => 'a', 'z' => 'C'];
asort($a, SORT_STRING | SORT_FLAG_CASE);
echo json_encode(array_values($a)), "\n";
$b = ['x' => 'B', 'y' => 'a', 'z' => 'C'];
arsort($b, SORT_STRING | SORT_FLAG_CASE);
echo json_encode(array_values($b)), "\n";
$c = ['B' => 1, 'a' => 2, 'C' => 3];
ksort($c, SORT_STRING | SORT_FLAG_CASE);
echo json_encode(array_keys($c)), "\n";
$d = ['B' => 1, 'a' => 2, 'C' => 3];
krsort($d, SORT_STRING | SORT_FLAG_CASE);
echo json_encode(array_keys($d)), "\n";
--EXPECT--
["a","B","C"]
["C","B","a"]
["a","B","C"]
["C","B","a"]
