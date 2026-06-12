--TEST--
stdlib array_map() JIT — null zip and closure multi-array (#4539)
--FILE--
<?php
var_export(array_map(null, [1, 2], ['a', 'b']));
echo "\n";
var_export(array_map(fn ($a, $b) => $a + $b, [1, 2], [10, 20]));
echo "\n";
--EXPECT--
array (
  0 => array (
    0 => 1,
    1 => 'a',
  ),
  1 => array (
    0 => 2,
    1 => 'b',
  ),
)
array (
  0 => 11,
  1 => 22,
)
