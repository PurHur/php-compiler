--TEST--
DateInterval::createFromDateString("") throws DateMalformedIntervalStringException (#29290, ext/date/php_date.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(function (int $errno, string $errstr): bool {
    echo "ERR:$errno:$errstr\n";
    return true;
});
try {
    $r = DateInterval::createFromDateString('');
    echo 'method:ret=', var_export($r, true), "\n";
} catch (Throwable $e) {
    echo 'method:', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    $r = date_interval_create_from_date_string('');
    echo 'proc:ret=', var_export($r, true), "\n";
} catch (Throwable $e) {
    echo 'proc:', get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
method:DateMalformedIntervalStringException:Unknown or bad format () at position 0 ( ): Empty string
ERR:2:date_interval_create_from_date_string(): Unknown or bad format () at position 0 ( ): Empty string
proc:ret=false
