--TEST--
array_chunk(): length <= 0 throws ValueError (#4090)
--FILE--
<?php
try {
    array_chunk([1, 2, 3], 0);
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
try {
    array_chunk([1, 2, 3], -1);
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
ValueError
array_chunk(): Argument #2 ($length) must be greater than 0
ValueError
array_chunk(): Argument #2 ($length) must be greater than 0
