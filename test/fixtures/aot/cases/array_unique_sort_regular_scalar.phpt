--TEST--
AOT: array_unique() SORT_REGULAR — int/string/float equivalents dedupe (#9421)
--FILE--
<?php
var_export(array_unique([1, '1', 1.0], SORT_REGULAR));
echo PHP_EOL;
var_export(array_unique([1, 2, 1], SORT_REGULAR));
echo PHP_EOL;
--EXPECT--
array (
  0 => 1,
)
array (
  0 => 1,
  1 => 2,
)
