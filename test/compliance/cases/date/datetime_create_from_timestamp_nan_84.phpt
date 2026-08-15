--TEST--
DateTime(Immutable)::createFromTimestamp NAN/INF DateRangeError finite-range wording (#31119, ext/date/php_date.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
date_default_timezone_set('UTC');
foreach ([
    ['DateTime', NAN],
    ['DateTime', INF],
    ['DateTime', -INF],
    ['DateTimeImmutable', NAN],
] as [$class, $ts]) {
    try {
        $class::createFromTimestamp($ts);
        echo "ok\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}
echo DateTime::createFromTimestamp(123.456789)->format('Y-m-d H:i:s.u'), "\n";
--EXPECT--
DateRangeError: DateTime::createFromTimestamp(): Argument #1 ($timestamp) must be a finite number between -9223372036854775808 and 9223372036854775807.999999, NAN given
DateRangeError: DateTime::createFromTimestamp(): Argument #1 ($timestamp) must be a finite number between -9223372036854775808 and 9223372036854775807.999999, INF given
DateRangeError: DateTime::createFromTimestamp(): Argument #1 ($timestamp) must be a finite number between -9223372036854775808 and 9223372036854775807.999999, -INF given
DateRangeError: DateTimeImmutable::createFromTimestamp(): Argument #1 ($timestamp) must be a finite number between -9223372036854775808 and 9223372036854775807.999999, NAN given
1970-01-01 00:02:03.456789
