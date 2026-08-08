--TEST--
range() zero / oversized step ValueError messages under PROFILE=8.4 forward (#28537)
--ENV--
PHP_COMPILER_PROFILE=8.4 forward
--FILE--
<?php
try {
    range(1, 2, 0);
    echo "zero:ok\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
try {
    range(1, 10, 100);
    echo "span:ok\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
ValueError
range(): Argument #3 ($step) cannot be 0
ValueError
range(): Argument #3 ($step) must be less than the range spanned by argument #1 ($start) and argument #2 ($end)
