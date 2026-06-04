--TEST--
stdlib array_intersect_assoc() JIT (#3129)
--FILE--
<?php
$a = ['k' => 1, 'x' => 2];
$b = ['k' => 1, 'y' => 3, 'x' => 2];
$i = array_intersect_assoc($a, $b);
echo count($i), "\n";
echo $i['k'], "\n";
echo $i['x'], "\n";
--EXPECT--
2
1
2
