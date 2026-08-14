--TEST--
AOT: DateTime::setMicrosecond out-of-range DateRangeError (#31118)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    (new DateTime('2020-01-01'))->setMicrosecond(1000000);
    echo "ok\n";
} catch (Throwable $e) {
    echo ($e instanceof DateRangeError ? 'DateRangeError' : get_class($e)), ': ', $e->getMessage(), "\n";
}
--EXPECT--
DateRangeError: DateTime::setMicrosecond(): Argument #1 ($microsecond) must be between 0 and 999999, 1000000 given
