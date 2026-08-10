--TEST--
date DateTimeZone::__construct(null) JIT TypeError cites Argument #1 (#29827, ext/date/php_date.c)
--JIT--
--FILE--
<?php
declare(strict_types=1);
try {
    new DateTimeZone(null);
    echo "uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
TypeError
DateTimeZone::__construct(): Argument #1 ($timezone) must be of type string, null given
