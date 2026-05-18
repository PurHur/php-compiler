--TEST--
stdlib array_fill, range, and array_combine together
--FILE--
<?php
$keys = range(0, 2);
$vals = array_fill(0, 3, 'v');
$c = array_combine($keys, $vals);
echo count($c), "\n";
echo $c[0], '|', $c[1], '|', $c[2], "\n";
echo bin2hex('Hi'), "\n";
--EXPECT--
3
v|v|v
4869
