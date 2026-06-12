--TEST--
stdlib array_map() — closure over multiple arrays (#4539, ext/standard/array.c)
--FILE--
<?php
var_export(array_map(fn ($a, $b) => $a + $b, [1, 2], [10, 20]));
echo "\n";
--EXPECT--
array (
  0 => 11,
  1 => 22,
)
