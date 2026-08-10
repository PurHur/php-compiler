--TEST--
DateTime::setISODate(null) / date_isodate_set TypeError under strict_types (#29842, ext/date/php_date.c)
--FILE--
<?php
declare(strict_types=1);
try {
    (new DateTime('2020-01-01'))->setISODate(null, 1);
    echo "DateTime:fail\n";
} catch (Throwable $e) {
    echo 'DateTime:', get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
try {
    (new DateTimeImmutable('2020-01-01'))->setISODate(null, 1);
    echo "DateTimeImmutable:fail\n";
} catch (Throwable $e) {
    echo 'DateTimeImmutable:', get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
try {
    date_isodate_set(date_create('2020-01-01'), null, 1);
    echo "proc:fail\n";
} catch (Throwable $e) {
    echo 'proc:', get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
DateTime:TypeError
DateTime::setISODate(): Argument #1 ($year) must be of type int, null given
DateTimeImmutable:TypeError
DateTimeImmutable::setISODate(): Argument #1 ($year) must be of type int, null given
proc:TypeError
date_isodate_set(): Argument #2 ($year) must be of type int, null given
