--TEST--
stdlib array_diff_uassoc/intersect_uassoc thin AOT (#27218, ext/standard/array.c)
--FILE--
<?php
$a = ['a' => 1, 'b' => 2, 'c' => 3];
$b = ['a' => 1, 'b' => 20, 'd' => 4];
$r = array_diff_uassoc($a, $b, fn ($x, $y) => $x <=> $y);
echo 'diff_uassoc=', implode(',', $r), ' keys=', implode(',', array_keys($r)), "\n";
$r = array_intersect_uassoc($a, $b, fn ($x, $y) => $x <=> $y);
echo 'intersect_uassoc=', implode(',', $r), ' keys=', implode(',', array_keys($r)), "\n";
--EXPECT--
diff_uassoc=2,3 keys=b,c
intersect_uassoc=1 keys=a
