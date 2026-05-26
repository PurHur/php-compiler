--TEST--
stdlib ksort()
--FILE--
<?php
$a = array('b' => 2, 'a' => 1, 'c' => 3);
ksort($a);
echo implode(',', array_keys($a)), "\n";
$b = array(2 => 'y', 0 => 'z', 1 => 'x');
ksort($b);
echo implode(',', array_keys($b)), "\n";
$c = array(1, 2, 3);
ksort($c);
echo implode(',', array_keys($c)), "\n";
--EXPECT--
a,b,c
0,1,2
0,1,2
