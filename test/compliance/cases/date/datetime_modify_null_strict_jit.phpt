--TEST--
DateTime(Immutable)::modify(null) JIT TypeError under strict_types (#29818, ext/date/php_date.c)
--JIT--
--FILE--
<?php
declare(strict_types=1);
foreach (['DateTime', 'DateTimeImmutable'] as $class) {
    try {
        (new $class('2020-01-01'))->modify(null);
        echo "$class:fail\n";
    } catch (Throwable $e) {
        echo "$class:", get_class($e), "\n";
        echo $e->getMessage(), "\n";
    }
}
--EXPECT--
DateTime:TypeError
DateTime::modify(): Argument #1 ($modifier) must be of type string, null given
DateTimeImmutable:TypeError
DateTimeImmutable::modify(): Argument #1 ($modifier) must be of type string, null given
