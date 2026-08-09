--TEST--
range() bool $step coerces like Z_PARAM_NUMBER under PROFILE=8.4 (JIT) (#29505)
--ENV--
PHP_COMPILER_PROFILE=8.4 forward
--FILE--
<?php
error_reporting(E_ALL);
try {
    echo implode(',', range(1, 5, true)), "\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
try {
    echo implode(',', range(1, 5, false)), "\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
1,2,3,4,5
ValueError
range(): Argument #3 ($step) cannot be 0
