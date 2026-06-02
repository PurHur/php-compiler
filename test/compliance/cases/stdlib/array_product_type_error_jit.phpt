--TEST--
stdlib array_product() JIT — invalid element TypeError (#4262)
--FILE--
<?php
try {
    array_product([1, 'x']);
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
TypeError
array_product(): Argument #1 ($array) must contain only int and float values
