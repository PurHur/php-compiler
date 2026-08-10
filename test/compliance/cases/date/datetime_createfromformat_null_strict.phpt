--TEST--
DateTime(Immutable)::createFromFormat(null) TypeError under strict_types (#29830, ext/date/php_date.c)
--FILE--
<?php
declare(strict_types=1);
try {
    DateTime::createFromFormat(null, 'x');
    echo "DateTime:fail\n";
} catch (Throwable $e) {
    echo 'DateTime:', get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
try {
    DateTimeImmutable::createFromFormat(null, 'x');
    echo "DateTimeImmutable:fail\n";
} catch (Throwable $e) {
    echo 'DateTimeImmutable:', get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
DateTime:TypeError
DateTime::createFromFormat(): Argument #1 ($format) must be of type string, null given
DateTimeImmutable:TypeError
DateTimeImmutable::createFromFormat(): Argument #1 ($format) must be of type string, null given
