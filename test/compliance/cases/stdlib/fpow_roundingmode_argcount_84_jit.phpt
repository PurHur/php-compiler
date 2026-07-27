--TEST--
stdlib fpow() rejects 3rd RoundingMode under PROFILE=8.4 JIT (#23577)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    var_export(fpow(2.5, 2.0, RoundingMode::HalfAwayFromZero));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
ArgumentCountError: fpow() expects exactly 2 arguments, 3 given
