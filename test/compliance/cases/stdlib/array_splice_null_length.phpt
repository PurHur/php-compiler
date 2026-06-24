--TEST--
stdlib array_splice() null length removes tail (ext/standard/array.c; #11209)
--FILE--
<?php
$a = array(1, 2, 3, 4);
$r = array_splice($a, 1, null);
echo count($a), "\n";
echo $a[0], "\n";
echo count($r), "\n";
echo $r[0], "\n";
echo $r[1], "\n";
echo $r[2], "\n";

$b = array(1, 2, 3);
array_splice($b, 0, null);
echo count($b), "\n";
--EXPECT--
1
1
3
2
3
4
0
