--TEST--
stdlib array_multisort() JIT (#1212)
--FILE--
<?php
$a = array();
$a[] = 30;
$a[] = 10;
$a[] = 20;
$b = array();
$b[] = 'c';
$b[] = 'a';
$b[] = 'b';
array_multisort($a, $b);
echo implode(',', $a), "\n";
echo implode(',', $b), "\n";
$c = array();
$c[] = 'z';
$c[] = 'x';
$c[] = 'y';
$d = array();
$d[] = 3;
$d[] = 1;
$d[] = 2;
array_multisort($d, $c, 3);
echo implode(',', $d), "\n";
echo implode(',', $c), "\n";
--EXPECT--
10,20,30
a,b,c
3,2,1
z,y,x
