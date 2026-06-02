--TEST--
stdlib array_filter() preserves source keys (ext/standard/array.c)
--FILE--
<?php
var_export(array_filter([1, 0, 2], 'intval'));
echo "\n";
var_export(array_filter([1, 0, 2], static fn ($v) => $v > 0));
echo "\n";
var_export(array_filter([1, 0, 2]));
echo "\n";
--EXPECT--
array (
  0 => 1,
  2 => 2,
)
array (
  0 => 1,
  2 => 2,
)
array (
  0 => 1,
  2 => 2,
)
