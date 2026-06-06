--TEST--
stdlib array_is_assoc() JIT — TypeError/ArgumentCountError parity (#7016, ext/standard/array.c)
--FILE--
<?php
var_dump(array_is_assoc([]));
try {
    var_dump(array_is_assoc('not array'));
} catch (TypeError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_dump(array_is_assoc());
} catch (ArgumentCountError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
bool(false)
TypeError: array_is_assoc(): Argument #1 ($array) must be of type array, string given
ArgumentCountError: array_is_assoc() expects exactly 1 argument, 0 given
