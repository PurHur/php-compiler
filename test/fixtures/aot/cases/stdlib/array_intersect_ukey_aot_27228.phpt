--TEST--
stdlib array_intersect_ukey() thin AOT with arrow key comparator (#27228, ext/standard/array.c)
--FILE--
<?php
$a = ['a' => 1, 'b' => 2, 'c' => 3];
$b = ['a' => 4, 'c' => 9];
$r = array_intersect_ukey($a, $b, fn ($k1, $k2) => $k1 <=> $k2);
echo implode(',', $r), ' keys=', implode(',', array_keys($r)), "\n";
--EXPECT--
1,3 keys=a,c
