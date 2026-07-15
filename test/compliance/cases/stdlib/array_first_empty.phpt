--TEST--
stdlib array_first()/array_last() empty array returns NULL (#19173, ext/standard/array.c)
--FILE--
<?php
var_export(array_first([]));
echo "\n";
var_export(array_last([]));
echo "\n";
$allUnset = [0 => 1];
unset($allUnset[0]);
var_export(array_first($allUnset));
echo "\n";
var_export(array_last($allUnset));
echo "\n";
var_export(array_first([1, 2, 3]));
echo "\n";
var_export(array_last([1, 2, 3]));
echo "\n";
?>
--EXPECT--
NULL
NULL
NULL
NULL
1
3
