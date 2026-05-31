--TEST--
AOT: array_chunk() preserve_keys=true on associative arrays
--FILE--
<?php
$c = array_chunk(['a' => 1, 'b' => 2, 'c' => 3], 2, true);
echo count($c), "\n";
$chunk0 = $c[0];
echo count($chunk0), "\n";
echo $chunk0['a'], "\n";
echo $chunk0['b'], "\n";
$chunk1 = $c[1];
echo count($chunk1), "\n";
echo $chunk1['c'], "\n";
--EXPECT--
2
2
1
2
1
3
