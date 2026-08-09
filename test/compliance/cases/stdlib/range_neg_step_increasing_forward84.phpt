--TEST--
range() negative step on increasing range ValueError under PROFILE=8.4 (#29351)
--ENV--
PHP_COMPILER_PROFILE=8.4 forward
--FILE--
<?php
try {
    range(1, 5, -1);
    echo "inc:ok\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
try {
    echo implode(',', range(5, 1, 1)), "\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
try {
    echo implode(',', range(5, 5, -1)), "\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
try {
    range(1.0, 5.0, -1.0);
    echo "float:ok\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
try {
    range('a', 'e', -1);
    echo "char:ok\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
ValueError
range(): Argument #3 ($step) must be greater than 0 for increasing ranges
5,4,3,2,1
5
ValueError
range(): Argument #3 ($step) must be greater than 0 for increasing ranges
ValueError
range(): Argument #3 ($step) must be greater than 0 for increasing ranges
