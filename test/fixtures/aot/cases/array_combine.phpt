--TEST--
AOT: array_combine() for string and integer keys
--FILE--
<?php
$c = array_combine(array('a', 'b'), array(1, 2));
$ca = $c['a'];
$cb = $c['b'];
echo $ca, '|', $cb, "\n";
$d = array_combine(array(0, 1), array('x', 'y'));
$dx = $d[0];
$dy = $d[1];
echo $dx, '|', $dy, "\n";
--EXPECT--
1|2
x|y
