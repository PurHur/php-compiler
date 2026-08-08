--TEST--
JIT: array_key_first() — TypeError on non-array
--FILE--
<?php
try {
    array_key_first(true);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: array_key_first(): Argument #1 ($array) must be of type array, true given
