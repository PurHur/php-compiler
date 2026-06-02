--TEST--
array_combine(): empty keys or values return false (ext/standard/array.c #4353)
--FILE--
<?php
var_export(array_combine([], []));
echo "\n";
var_export(array_combine(['a'], []));
echo "\n";
var_export(array_combine([], ['x']));
echo "\n";
--EXPECT--
false
false
false
