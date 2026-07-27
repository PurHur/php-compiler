--TEST--
stdlib number_format() rejects 5th RoundingMode under PROFILE=8.4 JIT (#23575)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    echo number_format(1.5, 0, '.', '', RoundingMode::HalfAwayFromZero), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
ArgumentCountError: number_format() expects at most 4 arguments, 5 given
