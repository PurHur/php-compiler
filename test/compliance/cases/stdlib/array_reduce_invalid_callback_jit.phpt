--TEST--
stdlib array_reduce() JIT — invalid callback TypeError even on empty array (#6679)
--FILE--
<?php
try {
    array_reduce([], null);
    echo "uncaught null\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    array_reduce([], 'not_a_real_function_xyz');
    echo "uncaught string\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

function sum(int $carry, int $item): int
{
    return $carry + $item;
}
echo array_reduce(array(), 'sum', 0), "\n";
--EXPECT--
array_reduce(): Argument #2 ($callback) must be a valid callback, no array or string given
array_reduce(): Argument #2 ($callback) must be a valid callback, function "not_a_real_function_xyz" not found or invalid function name
0
