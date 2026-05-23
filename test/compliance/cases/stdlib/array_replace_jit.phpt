--TEST--
stdlib array_replace() JIT for list and string keys
--FILE--
<?php
$a = array(1, 2, 3);
$b = array(0 => 10, 2 => 30, 3 => 40);
$r = array_replace($a, $b);
echo count($r), "\n";
echo $r[0], "\n";
echo $r[2], "\n";
$x = array('color' => 'red');
$y = array('color' => 'green');
$z = array_replace($x, $y);
echo $z['color'], "\n";
--EXPECT--
4
10
30
green
