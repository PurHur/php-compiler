--TEST--
AOT array_replace() on list and string keys
--FILE--
<?php
$a = array(1, 2);
$b = array(0 => 9);
$r = array_replace($a, $b);
echo $r[0], "\n";
echo $r[1], "\n";
$x = array('k' => 'old');
$y = array('k' => 'new');
$z = array_replace($x, $y);
echo $z['k'], "\n";
--EXPECT--
9
2
new
