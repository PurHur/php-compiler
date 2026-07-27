--TEST--
stdlib fpow() rejects 3rd RoundingMode under PROFILE=8.4 (#23577, re-#9990, ext/standard/math.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
var_export(fpow(2.0, 3.0));
echo "\n";
try {
    var_export(fpow(2.5, 2.0, RoundingMode::HalfAwayFromZero));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export(fpow(10.0, -1.0, rounding_mode: RoundingMode::HalfAwayFromZero));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
8.0
ArgumentCountError: fpow() expects exactly 2 arguments, 3 given
Error: Unknown named parameter $rounding_mode
