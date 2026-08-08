--TEST--
JIT: range() zero / oversized step ValueError messages under PROFILE=8.4 forward (#28537)
--ENV--
PHP_COMPILER_PROFILE=8.4 forward
--FILE--
<?php
$start = 1;
$end = 2;
$step = 0;
try {
    range($start, $end, $step);
    echo "zero:ok\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
$start = 1;
$end = 10;
$step = 100;
try {
    range($start, $end, $step);
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
