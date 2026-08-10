--TEST--
DateTime::setTimestamp(null) / date_timestamp_set TypeError under strict_types (#29841, ext/date/php_date.c)
--FILE--
<?php
declare(strict_types=1);
try {
    (new DateTime('2020-01-01'))->setTimestamp(null);
    echo "DateTime:fail\n";
} catch (Throwable $e) {
    echo 'DateTime:', get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
try {
    (new DateTimeImmutable('2020-01-01'))->setTimestamp(null);
    echo "DateTimeImmutable:fail\n";
} catch (Throwable $e) {
    echo 'DateTimeImmutable:', get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
try {
    date_timestamp_set(date_create('@1'), null);
    echo "proc:fail\n";
} catch (Throwable $e) {
    echo 'proc:', get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
DateTime:TypeError
DateTime::setTimestamp(): Argument #1 ($timestamp) must be of type int, null given
DateTimeImmutable:TypeError
DateTimeImmutable::setTimestamp(): Argument #1 ($timestamp) must be of type int, null given
proc:TypeError
date_timestamp_set(): Argument #2 ($timestamp) must be of type int, null given
