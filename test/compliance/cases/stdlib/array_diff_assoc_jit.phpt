--TEST--
stdlib array_diff_assoc() JIT (#3129)
--FILE--
<?php
$a = ['k' => 1, 'x' => 2];
$b = ['k' => 1, 'y' => 3];
$d = array_diff_assoc($a, $b);
echo count($d), "\n";
echo $d['x'], "\n";
--EXPECT--
1
2
