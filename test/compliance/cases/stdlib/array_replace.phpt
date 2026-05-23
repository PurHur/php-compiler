--TEST--
stdlib array_replace() for list and string keys
--FILE--
<?php
$a = array(1, 2, 3);
$b = array(0 => 10, 2 => 30, 3 => 40);
$r = array_replace($a, $b);
echo count($r), "\n";
echo $r[0], "\n";
echo $r[1], "\n";
echo $r[2], "\n";
echo $r[3], "\n";
$x = array('color' => 'red', 'shape' => 'circle');
$y = array('color' => 'green', 'size' => 5);
$z = array_replace($x, $y);
echo $z['color'], "\n";
echo $z['shape'], "\n";
echo $z['size'], "\n";
--EXPECT--
4
10
2
30
40
green
circle
5
