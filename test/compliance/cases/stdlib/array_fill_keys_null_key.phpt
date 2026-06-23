--TEST--
stdlib array_fill_keys() — null key coerces to empty string (ext/standard/array.c)
--FILE--
<?php
var_export(array_fill_keys([null], 'x'));
echo "\n";
--EXPECT--
array (
  '' => 'x',
)
