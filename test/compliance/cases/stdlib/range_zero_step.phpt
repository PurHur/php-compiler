--TEST--
range(): zero step throws ValueError (#4947)
--FILE--
<?php
try {
    range(0, 1, 0);
    echo "no_ex\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
ValueError
range(): Argument #3 ($step) must not exceed the specified range
