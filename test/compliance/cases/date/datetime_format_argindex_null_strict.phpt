--TEST--
DateTime(Immutable)::format(null) TypeError cites Argument #1 (#29819, ext/date/php_date.c)
--FILE--
<?php
declare(strict_types=1);
foreach (['DateTime', 'DateTimeImmutable'] as $class) {
    try {
        (new $class('2020-01-01'))->format(null);
        echo "$class:fail\n";
    } catch (Throwable $e) {
        echo "$class:", get_class($e), "\n";
        echo $e->getMessage(), "\n";
    }
}
--EXPECT--
DateTime:TypeError
DateTime::format(): Argument #1 ($format) must be of type string, null given
DateTimeImmutable:TypeError
DateTimeImmutable::format(): Argument #1 ($format) must be of type string, null given
