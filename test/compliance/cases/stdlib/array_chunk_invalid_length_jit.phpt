--TEST--
array_chunk(): runtime length <= 0 throws ValueError on JIT (#4090)
--FILE--
<?php
$length = -2;
try {
    array_chunk([1, 2, 3], $length);
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
ValueError
array_chunk(): Argument #2 ($length) must be greater than 0
