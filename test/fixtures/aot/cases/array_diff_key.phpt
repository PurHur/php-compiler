--TEST--
AOT array_diff_key() key-only diff (#4188)
--FILE--
<?php
$a = ['a' => 1, 'b' => 2];
$b = ['a' => 9, 'c' => 3];
$d = array_diff_key($a, $b);
$i = array_intersect_key($a, $b);
echo count($d), "\n";
echo $d['b'], "\n";
echo count($i), "\n";
echo $i['a'], "\n";
--EXPECT--
1
2
1
1
