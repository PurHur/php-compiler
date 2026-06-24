--TEST--
AOT array_merge() string-key map then list operand appends (#11155)
--FILE--
<?php
$m = array_merge(['x' => 1], [0 => 'a', 1 => 'b']);
echo count($m), "\n";
echo $m['x'], "\n";
echo $m[0], "\n";
echo $m[1], "\n";
--EXPECT--
3
1
a
b
