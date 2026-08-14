--TEST--
stdlib JIT: password_get_info() excess argc → ArgumentCountError (#30712)
--FILE--
<?php
try {
    password_get_info('x', 1);
    echo "NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
ArgumentCountError: password_get_info() expects exactly 1 argument, 2 given
