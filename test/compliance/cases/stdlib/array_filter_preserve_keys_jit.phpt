--TEST--
stdlib array_filter() preserves keys JIT default mask
--FILE--
<?php
var_export(array_filter([1, 0, 2]));
echo "\n";
--EXPECT--
array (
  0 => 1,
  2 => 2,
)
