--TEST--
stdlib array_is_list() — TypeError/ArgumentCountError parity (#4389, ext/standard/array.c)
--FILE--
<?php
var_dump(array_is_list([]));
try {
    var_dump(array_is_list('not array'));
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_dump(array_is_list());
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
bool(true)
TypeError: array_is_list(): Argument #1 ($array) must be of type array, string given
ArgumentCountError: array_is_list() expects exactly 1 argument, 0 given
