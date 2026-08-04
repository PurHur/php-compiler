--TEST--
AOT array_diff_key() / array_intersect_key() key-only (#4188, #27521, #27522)
--FILE--
<?php
$d = array_diff_key(['a' => 1, 'b' => 2, 'c' => 3], ['a' => 9, 'c' => 8]);
$i = array_intersect_key(['a' => 1, 'b' => 2, 'c' => 3], ['a' => 9, 'c' => 8]);
echo 'diff keys=', implode(',', array_keys($d)), ' vals=', implode(',', array_values($d)), "\n";
echo 'intersect keys=', implode(',', array_keys($i)), ' vals=', implode(',', array_values($i)), "\n";
$packed = array_intersect_key([10, 20, 30], [0 => 1, 2 => 1]);
echo 'packed=', implode(',', $packed), "\n";
$x = [10, 20, 30];
$y = [0 => 1, 2 => 1];
echo 'packed_var=', implode(',', array_intersect_key($x, $y)), "\n";
$a = ['a' => 1, 'b' => 2, 'c' => 3];
$b = ['a' => 9, 'c' => 8];
count($a);
count($b);
$dv = array_diff_key($a, $b);
$iv = array_intersect_key($a, $b);
echo 'vars diff=', implode(',', array_keys($dv)), ' intersect=', implode(',', array_keys($iv)), "\n";
--EXPECT--
diff keys=b vals=2
intersect keys=a,c vals=1,3
packed=10,30
packed_var=10,30
vars diff=b intersect=a,c
