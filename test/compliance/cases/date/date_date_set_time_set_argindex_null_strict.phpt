--TEST--
date_date_set/date_time_set(null) TypeError cites Argument #2 (#29863, ext/date/php_date.c)
--FILE--
<?php
declare(strict_types=1);
$d = new DateTime('@0');
try {
    date_date_set($d, null, 1, 1);
    echo "date_date_set:fail\n";
} catch (Throwable $e) {
    echo 'date_date_set:', get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
try {
    date_time_set($d, null, 0);
    echo "date_time_set:fail\n";
} catch (Throwable $e) {
    echo 'date_time_set:', get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
date_date_set:TypeError
date_date_set(): Argument #2 ($year) must be of type int, null given
date_time_set:TypeError
date_time_set(): Argument #2 ($hour) must be of type int, null given
