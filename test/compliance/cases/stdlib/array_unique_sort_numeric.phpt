--TEST--
stdlib array_unique() SORT_NUMERIC flag (#4253, #29113, ext/standard/array.c)
--FILE--
<?php
var_export(array_unique(['10', '10a'], SORT_NUMERIC));
echo PHP_EOL;
var_export(array_unique([10, 10], SORT_NUMERIC));
echo PHP_EOL;
var_export(array_unique(['10', 10], SORT_NUMERIC));
echo PHP_EOL;
$u = array_unique(['1', 1, '1.0', 1.0], SORT_NUMERIC);
echo count($u), ',', implode(',', array_map('strval', $u)), PHP_EOL;
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
1,1
