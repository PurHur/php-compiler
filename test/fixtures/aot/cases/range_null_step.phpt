--TEST--
AOT range() null $step → ValueError cannot be 0 (#29352)
--ENV--
PHP_COMPILER_PROFILE=8.4 forward
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
try {
    range(0, 2, null);
    echo "no_ex\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
ValueError
range(): Argument #3 ($step) cannot be 0
