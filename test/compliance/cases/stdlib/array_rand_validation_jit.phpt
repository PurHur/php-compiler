--TEST--
JIT array_rand() argument validation (#4198)
--FILE--
<?php
try {
    array_rand([]);
    echo "empty: no_ex\n";
} catch (ValueError $e) {
    echo 'empty: ', $e->getMessage(), "\n";
}
try {
    array_rand(['a'], 0);
    echo "num_zero: no_ex\n";
} catch (ValueError $e) {
    echo 'num_zero: ', $e->getMessage(), "\n";
}
try {
    array_rand(['a', 'b'], 3);
    echo "num_exceed: no_ex\n";
} catch (ValueError $e) {
    echo 'num_exceed: ', $e->getMessage(), "\n";
}
--EXPECT--
empty: array_rand(): Argument #1 ($array) must not be empty
num_zero: array_rand(): Argument #2 ($num) must be between 1 and the number of elements in argument #1 ($array)
num_exceed: array_rand(): Argument #2 ($num) must be between 1 and the number of elements in argument #1 ($array)
