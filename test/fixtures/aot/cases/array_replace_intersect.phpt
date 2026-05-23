--TEST--
AOT: array_replace() and array_intersect()
--FILE--
<?php
$a = array('k' => 1, 'j' => 2);
$b = array('j' => 9);
$r = array_replace($a, $b);
echo $r['k'], "\n";
echo $r['j'], "\n";
$x = array(1, 2);
$y = array(2, 3);
$i = array_intersect($x, $y);
echo count($i), "\n";
echo $i[1], "\n";
--EXPECT--
1
9
1
2
