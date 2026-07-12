--TEST--
stdlib array_first()/array_last() empty array ValueError (PHP 8.4, ext/standard/array.c)
--FILE--
<?php
foreach (['array_first', 'array_last'] as $fn) {
    try {
        $fn([]);
        echo $fn, ": uncaught\n";
    } catch (ValueError $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    }
}
var_export(array_first([1, 2, 3]));
echo "\n";
var_export(array_last([1, 2, 3]));
echo "\n";
?>
--EXPECT--
array_first: array_first(): Argument #1 ($array) must not be empty
array_last: array_last(): Argument #1 ($array) must not be empty
1
3

