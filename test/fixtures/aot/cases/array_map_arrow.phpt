--TEST--
AOT: array_map() with arrow closure callback (#142)
--FILE--
<?php
$d = array_map(fn($x) => $x * 2, [1, 2, 3]);
echo $d[0], ',', $d[1], ',', $d[2];
--EXPECT--
2,4,6
