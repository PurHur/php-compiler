--TEST--
AOT: array_unique() SORT_NUMERIC — numeric dedup (#4253)
--FILE--
<?php
var_export(array_unique(['10', '10a'], SORT_NUMERIC));
echo PHP_EOL;
var_export(array_unique([10, 10], SORT_NUMERIC));
echo PHP_EOL;
--EXPECT--
array (
  0 => '10',
)
array (
  0 => 10,
)
