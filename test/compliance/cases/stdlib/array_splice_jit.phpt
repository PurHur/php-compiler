--TEST--
stdlib array_splice() JIT (#1205)
--FILE--
<?php
$a = array(0, 1, 2, 3, 4);
$r = array_splice($a, 1, 2, array(9, 10));
echo count($r), "\n";
echo $r[0], "\n";
echo $r[1], "\n";
echo count($a), "\n";
echo $a[0], "\n";
echo $a[1], "\n";
echo $a[2], "\n";
echo $a[3], "\n";
echo $a[4], "\n";

$b = array(0, 1, 2, 3, 4);
$t = array_splice($b, -2);
echo count($t), "\n";
echo $t[0], "\n";
echo $t[1], "\n";
echo count($b), "\n";
echo $b[0], "\n";
echo $b[1], "\n";
echo $b[2], "\n";

$c = array(0, 1, 2);
$d = array_splice($c, 1, 0, array(5, 6));
echo count($d), "\n";
echo count($c), "\n";
echo $c[0], "\n";
echo $c[1], "\n";
echo $c[2], "\n";
echo $c[3], "\n";
echo $c[4], "\n";
--EXPECT--
2
1
2
5
0
9
10
3
4
2
3
4
3
0
1
2
0
5
0
5
6
1
2
