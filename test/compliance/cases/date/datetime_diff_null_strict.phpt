--TEST--
DateTime::diff(null) TypeError Argument #1 ($targetObject) (#29868, ext/date/php_date.c)
--FILE--
<?php
declare(strict_types=1);
try {
    (new DateTime('@0'))->diff(null);
    echo "dt:fail\n";
} catch (Throwable $e) {
    echo 'dt:', get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
try {
    (new DateTimeImmutable('@0'))->diff(null);
    echo "dti:fail\n";
} catch (Throwable $e) {
    echo 'dti:', get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
dt:TypeError
DateTime::diff(): Argument #1 ($targetObject) must be of type DateTimeInterface, null given
dti:TypeError
DateTimeImmutable::diff(): Argument #1 ($targetObject) must be of type DateTimeInterface, null given
