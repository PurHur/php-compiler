--TEST--
stdlib array_multisort() reindexes numeric keys to 0..n-1 (#13449, ext/standard/array.c)
--FILE--
<?php
$a = [3 => 30, 1 => 10, 2 => 20];
array_multisort($a);
echo implode(',', array_keys($a)), "\n";
echo implode(',', $a), "\n";
$b = ['x' => 3, 'y' => 1];
array_multisort($b);
echo $b['y'], "\n";
echo $b['x'], "\n";
--EXPECT--
0,1,2
10,20,30
1
3
