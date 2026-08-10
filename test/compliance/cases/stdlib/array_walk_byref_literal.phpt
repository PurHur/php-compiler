--TEST--
stdlib array_walk()/array_walk_recursive() inline array by-ref Error names $array (#10819, ext/standard/array.c)
--FILE--
<?php
try {
    array_walk([1], null);
} catch (Error $e) {
    echo 'walk: ', $e->getMessage(), "\n";
}
try {
    array_walk_recursive([1], null);
} catch (Error $e) {
    echo 'rec: ', $e->getMessage(), "\n";
}
--EXPECT--
walk: array_walk(): Argument #1 ($array) could not be passed by reference
rec: array_walk_recursive(): Argument #1 ($array) could not be passed by reference
