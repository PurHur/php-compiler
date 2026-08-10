--TEST--
DateTime::setTimezone(null) TypeError Argument #1 ($timezone) (#29869, ext/date/php_date.c)
--FILE--
<?php
declare(strict_types=1);
$d = new DateTime('@0');
try {
    $d->setTimezone(null);
    echo "dt:fail\n";
} catch (Throwable $e) {
    echo 'dt:', get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
try {
    (new DateTimeImmutable('@0'))->setTimezone(null);
    echo "dti:fail\n";
} catch (Throwable $e) {
    echo 'dti:', get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
try {
    date_timezone_set($d, null);
    echo "proc:fail\n";
} catch (Throwable $e) {
    echo 'proc:', get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
dt:TypeError
DateTime::setTimezone(): Argument #1 ($timezone) must be of type DateTimeZone, null given
dti:TypeError
DateTimeImmutable::setTimezone(): Argument #1 ($timezone) must be of type DateTimeZone, null given
proc:TypeError
date_timezone_set(): Argument #2 ($timezone) must be of type DateTimeZone, null given
