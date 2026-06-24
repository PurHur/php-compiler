--TEST--
stdlib array_merge() string-key map then list operand appends (#11155, ext/standard/array.c)
--FILE--
<?php
$m = array_merge(['x' => 1], [0 => 'a', 1 => 'b']);
$listOnly = array_merge([0 => 'a'], [0 => 'b']);
echo count($m), "\n";
echo $m['x'], "\n";
echo $m[0], "\n";
echo $m[1], "\n";
echo count($listOnly), "\n";
echo $listOnly[0], "\n";
echo $listOnly[1], "\n";
--EXPECT--
3
1
a
b
2
a
b
