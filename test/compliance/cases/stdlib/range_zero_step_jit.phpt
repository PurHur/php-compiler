--TEST--
JIT: range() zero step throws ValueError (#4947)
--FILE--
<?php
$start = 0;
$end = 1;
$step = 0;
try {
    range($start, $end, $step);
    echo "no_ex\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
ValueError
range(): Argument #3 ($step) must not exceed the specified range
