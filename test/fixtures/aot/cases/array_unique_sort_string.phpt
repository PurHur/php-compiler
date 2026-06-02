--TEST--
AOT: array_unique() SORT_STRING — string cast dedup
--FILE--
<?php
$a = array(1, '1', 2);
$u = array_unique($a, SORT_STRING);
echo count($u), "\n";
$v = array_values($u);
echo $v[0], "\n";
echo $v[1], "\n";
--EXPECT--
2
1
2
