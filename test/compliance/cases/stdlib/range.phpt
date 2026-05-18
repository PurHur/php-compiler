--TEST--
stdlib range() for integers
--FILE--
<?php
$r = range(1, 4);
echo count($r), "\n";
echo $r[0], '|', $r[1], '|', $r[2], '|', $r[3], "\n";
$s = range(5, 1);
echo count($s), "\n";
echo $s[0], '|', $s[4], "\n";
$t = range(0, 6, 2);
echo count($t), "\n";
echo $t[0], '|', $t[1], '|', $t[2], "\n";
--EXPECT--
4
1|2|3|4
5
5|1
4
0|2|4|6
