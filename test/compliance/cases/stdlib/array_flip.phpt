--TEST--
stdlib array_flip() for string and int values
--FILE--
<?php
$a = array('a' => 1, 'b' => 2);
$f = array_flip($a);
echo $f[1], "\n";
echo $f[2], "\n";
$b = array(10 => 'x', 20 => 'y');
$g = array_flip($b);
echo $g['x'], "\n";
echo $g['y'], "\n";
--EXPECT--
a
b
10
20
