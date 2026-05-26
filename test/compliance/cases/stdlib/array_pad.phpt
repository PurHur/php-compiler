--TEST--
stdlib array_pad()
--FILE--
<?php
$a = array_pad(array(1, 2, 3), 6, 'x');
echo count($a), ':', $a[0], '|', $a[3], '|', $a[5], "\n";
$b = array_pad(array(1, 2), -4, 0);
echo count($b), ':', $b[0], '|', $b[2], "\n";
$c = array_pad(array(1, 2, 3), 2, 'z');
echo count($c), ':', $c[0], '|', $c[2], "\n";
$d = array_pad(array(), 3, 'e');
echo count($d), ':', $d[0], '|', $d[2], "\n";
--EXPECT--
6:1|x|x
4:0|1
3:1|3
3:e|e
