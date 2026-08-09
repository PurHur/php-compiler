--TEST--
JIT: range() negative step on increasing range ValueError under PROFILE=8.4 (#29351)
--ENV--
PHP_COMPILER_PROFILE=8.4 forward
--FILE--
<?php
$start = 1;
$end = 5;
$step = -1;
try {
    range($start, $end, $step);
    echo "inc:ok\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
$start = 5;
$end = 1;
$step = 1;
try {
    echo implode(',', range($start, $end, $step)), "\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
$start = 5;
$end = 5;
$step = -1;
try {
    echo implode(',', range($start, $end, $step)), "\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
ValueError
range(): Argument #3 ($step) must be greater than 0 for increasing ranges
5,4,3,2,1
5
