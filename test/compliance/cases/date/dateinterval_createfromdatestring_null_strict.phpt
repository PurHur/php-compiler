--TEST--
date DateInterval::createFromDateString(null) TypeError under strict_types (#29843, ext/date/php_date.c)
--FILE--
<?php
declare(strict_types=1);
try {
    DateInterval::createFromDateString(null);
    echo "uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
TypeError
DateInterval::createFromDateString(): Argument #1 ($datetime) must be of type string, null given
