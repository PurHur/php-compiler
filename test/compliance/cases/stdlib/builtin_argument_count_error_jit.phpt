--TEST--
stdlib builtins JIT — ArgumentCountError for wrong arity (#4145, ext/standard parity)
--FILE--
<?php
try {
    str_contains('abc');
} catch (ArgumentCountError $e) {
    echo 'str_contains: ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    array_key_exists('k');
} catch (ArgumentCountError $e) {
    echo 'array_key_exists: ', get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
str_contains: ArgumentCountError: str_contains() expects exactly 2 arguments, 1 given
array_key_exists: ArgumentCountError: array_key_exists() expects exactly 2 arguments, 1 given
