--TEST--
stdlib array_multisort() on two integer arrays (#1212)
--FILE--
<?php
$a = [30, 10, 20];
$b = ['c', 'a', 'b'];
array_multisort($a, $b);
echo implode(',', $a), "\n";
echo implode(',', $b), "\n";
$c = ['z', 'x', 'y'];
$d = [3, 1, 2];
array_multisort($d, $c, 3);
echo implode(',', $d), "\n";
echo implode(',', $c), "\n";
--EXPECT--
10,20,30
a,b,c
3,2,1
z,y,x
