--TEST--
date DateTimeZone::__construct() excess argc ArgumentCountError (#31068, ext/date/php_date.c)
--FILE--
<?php
declare(strict_types=1);
try {
    new DateTimeZone('UTC', 1);
    echo "uncaught\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
DateTimeZone::__construct() expects exactly 1 argument, 2 given
