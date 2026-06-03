--TEST--
stdlib array_combine() — TypeError for non-array operands (#4714)
--FILE--
<?php
try {
    array_combine('keys', [1]);
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
try {
    array_combine([1], 'values');
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
TypeError
array_combine(): Argument #1 ($keys) must be of type array, string given
TypeError
array_combine(): Argument #2 ($values) must be of type array, string given
