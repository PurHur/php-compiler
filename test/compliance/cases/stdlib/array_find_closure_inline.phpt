--TEST--
stdlib: array_any()/array_all()/array_find() inline Closure callbacks (#9154, ext/standard/array.c)
--FILE--
<?php
$arr = [1, 2, 3];
echo array_any($arr, fn ($v) => $v > 2) ? 'any' : 'none';
echo "\n";
echo array_all($arr, fn ($v) => $v > 0) ? 'all' : 'notall';
echo "\n";
var_export(array_find($arr, fn ($v) => $v === 2));
echo "\n";
var_export(array_find_key($arr, fn ($v) => $v === 2));
--EXPECT--
any
all
2
1
