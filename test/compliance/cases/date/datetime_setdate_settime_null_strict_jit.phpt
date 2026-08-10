--TEST--
DateTime::setDate/setTime(null) JIT TypeError under strict_types (#29829, ext/date/php_date.c)
--JIT--
--FILE--
<?php
declare(strict_types=1);
try {
    (new DateTime('2020-01-01'))->setDate(null, 1, 1);
    echo "setDate:fail\n";
} catch (Throwable $e) {
    echo 'setDate:', get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
try {
    (new DateTime('2020-01-01'))->setTime(null, 0);
    echo "setTime:fail\n";
} catch (Throwable $e) {
    echo 'setTime:', get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
setDate:TypeError
DateTime::setDate(): Argument #1 ($year) must be of type int, null given
setTime:TypeError
DateTime::setTime(): Argument #1 ($hour) must be of type int, null given
