--TEST--
stdlib array_udiff_assoc() — value comparator with exact key match (#11217, ext/standard/array.c)
--FILE--
<?php
var_export(array_udiff_assoc(['a' => 1], ['A' => 1], 'strcasecmp'));
echo "\n";
var_export(array_udiff_assoc(['a' => 1, 'b' => 2], ['A' => 1, 'c' => 3], 'strcasecmp'));
--EXPECT--
array (
  'a' => 1,
)
array (
  'a' => 1,
  'b' => 2,
)
