--TEST--
stdlib array_multisort() on associative arrays preserves string keys (#10653, ext/standard/array.c)
--FILE--
<?php
$a = array('x' => 3, 'y' => 1);
$b = array('x' => 2, 'y' => 4);
array_multisort($a, $b);
echo $a['y'], "\n";
echo $a['x'], "\n";
echo $b['y'], "\n";
echo $b['x'], "\n";
--EXPECT--
1
3
4
2
