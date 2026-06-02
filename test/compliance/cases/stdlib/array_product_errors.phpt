--TEST--
stdlib array_product() — ArgumentCountError and TypeError (#4283)
--FILE--
<?php
try {
    array_product();
} catch (Throwable $e) {
    echo 'argc: ', get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
try {
    array_product(1);
} catch (Throwable $e) {
    echo 'type: ', get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
argc: ArgumentCountError
array_product() expects exactly 1 argument, 0 given
type: TypeError
array_product(): Argument #1 ($array) must be of type array, int given
