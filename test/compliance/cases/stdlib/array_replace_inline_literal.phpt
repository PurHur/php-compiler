--TEST--
stdlib array_replace() inline array literal first argument (#10231, ext/standard/array.c)
--FILE--
<?php
var_export(array_replace(['a' => 1], ['a' => 2, 'b' => 3]));
echo "\n";
var_export(array_merge(['a' => 1], ['b' => 2]));
echo "\n";
?>
--EXPECT--
array (
  'a' => 2,
  'b' => 3,
)
array (
  'a' => 1,
  'b' => 2,
)
