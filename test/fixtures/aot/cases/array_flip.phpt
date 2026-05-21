--TEST--
AOT array_flip() for string and int values
--FILE--
<?php
$a = array('a' => 1, 'b' => 2);
$f = array_flip($a);
$fa = $f[1];
$fb = $f[2];
echo $fa, "\n";
echo $fb, "\n";
$b = array(10 => 'x', 20 => 'y');
$g = array_flip($b);
$k = 'x';
$gx = $g[$k];
echo $gx, "\n";
$k = 'y';
$gy = $g[$k];
echo $gy, "\n";
--EXPECT--
a
b
10
20
