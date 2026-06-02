--TEST--
stdlib array_product() — empty array and invalid element TypeError (#4262)
--FILE--
<?php
echo array_product([]), "\n";

try {
    array_product([1, 'x']);
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}

try {
    array_product([1, 'notnum']);
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
1
TypeError
array_product(): Argument #1 ($array) must contain only int and float values
TypeError
array_product(): Argument #1 ($array) must contain only int and float values
