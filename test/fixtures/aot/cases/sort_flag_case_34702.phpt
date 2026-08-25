--TEST--
AOT: sort()/rsort() honor SORT_STRING|SORT_FLAG_CASE (#34702, ext/standard/array.c)
--FILE--
<?php
$a = ['B', 'a', 'C'];
rsort($a, SORT_STRING | SORT_FLAG_CASE);
echo json_encode($a), "\n";
$b = ['B', 'a', 'C'];
sort($b, SORT_STRING | SORT_FLAG_CASE);
echo json_encode($b), "\n";
--EXPECT--
["C","B","a"]
["a","B","C"]
