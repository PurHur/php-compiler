--TEST--
stdlib array_slice() negative offset on inline associative array (#10809, ext/standard/array.c)
--FILE--
<?php
var_export(array_slice(['a' => 1, 'b' => 2, 'c' => 3], -2, 1, true));
echo "\n";
--EXPECT--
array (
  'b' => 2,
)
