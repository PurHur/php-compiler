--TEST--
stdlib min()/max() — array argument and variadic scalars
--FILE--
<?php
echo max([3, 1, 2]), "\n";
echo min(3, 1, 2), "\n";
echo max([1.5, 2, 0.5]), "\n";
echo min(1, 2.5, 0), "\n";
try {
    max([]);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    min([]);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
3
1
2
0
ValueError: max(): Argument #1 ($value) must contain at least one element
ValueError: min(): Argument #1 ($value) must contain at least one element
