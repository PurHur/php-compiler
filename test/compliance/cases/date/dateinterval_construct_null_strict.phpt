--TEST--
date DateInterval::__construct(null) TypeError under strict_types (#29828, ext/date/php_date.c)
--FILE--
<?php
declare(strict_types=1);
try {
    new DateInterval(null);
    echo "uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
TypeError
DateInterval::__construct(): Argument #1 ($duration) must be of type string, null given
