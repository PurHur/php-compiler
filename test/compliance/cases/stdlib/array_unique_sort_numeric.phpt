--TEST--
stdlib array_unique() SORT_NUMERIC flag (#4253, ext/standard/array.c)
--FILE--
<?php
var_export(array_unique(['10', '10a'], SORT_NUMERIC));
echo PHP_EOL;
var_export(array_unique([10, 10], SORT_NUMERIC));
echo PHP_EOL;
var_export(array_unique(['10', 10], SORT_NUMERIC));
echo PHP_EOL;
--EXPECT--
array (
  0 => '10',
)
array (
  0 => 10,
)
array (
  0 => '10',
)
