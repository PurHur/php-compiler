--TEST--
stdlib krsort()
--FILE--
<?php
$a = array('b' => 2, 'a' => 1, 'c' => 3);
krsort($a);
echo implode(',', array_keys($a)), "\n";
$b = array(2 => 'y', 0 => 'z', 1 => 'x');
krsort($b);
echo implode(',', array_keys($b)), "\n";
$c = array(1, 2, 3);
krsort($c);
echo implode(',', array_keys($c)), "\n";
--EXPECT--
c,b,a
2,1,0
2,1,0
