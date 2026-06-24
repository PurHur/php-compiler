--TEST--
stdlib array_combine() keys:/values: named parameters (#11346, ext/standard/array.c)
--FILE--
<?php
var_export(array_combine(keys: ['a', 'b'], values: [1, 2]));
echo "\n";
var_export(array_combine(['a'], values: [1]));
echo "\n";
--EXPECT--
array (
  'a' => 1,
  'b' => 2,
)
array (
  'a' => 1,
)
