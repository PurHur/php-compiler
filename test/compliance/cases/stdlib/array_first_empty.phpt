--TEST--
stdlib array_first()/array_last() empty array NULL (PHP 8.4, ext/standard/array.c)
--FILE--
<?php
var_export(array_first([]));
echo "\n";
var_export(array_last([]));
echo "\n";
var_export(array_first([1, 2, 3]));
echo "\n";
var_export(array_last([1, 2, 3]));
echo "\n";
?>
--EXPECT--
NULL
NULL
1
3
