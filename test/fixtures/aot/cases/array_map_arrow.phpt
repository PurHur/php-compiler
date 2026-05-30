--TEST--
AOT: array_map() with arrow closure callback (#142)
--FILE--
<?php
$d = array_map(fn($x) => $x * 2, [1, 2, 3]);
echo implode(',', $d);
--EXPECT--
2,4,6
