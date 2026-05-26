--TEST--
stdlib array_merge() for string-key associative arrays (#2287)
--FILE--
<?php
$a = array('x' => 1, 'y' => 2);
$b = array('y' => 3, 'z' => 4);
$m = array_merge($a, $b);
echo count($m), "\n";
echo $m['x'], "\n";
echo $m['y'], "\n";
echo $m['z'], "\n";
--EXPECT--
3
1
3
4
