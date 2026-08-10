--TEST--
timezone_name_get/timezone_offset_get(null) TypeError includes ", null given" (#29878, ext/date/php_date.c)
--FILE--
<?php
declare(strict_types=1);
try {
    timezone_name_get(null);
    echo "name:fail\n";
} catch (Throwable $e) {
    echo 'name:', get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
try {
    timezone_offset_get(null, new DateTime('now'));
    echo "offset:fail\n";
} catch (Throwable $e) {
    echo 'offset:', get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
name:TypeError
timezone_name_get(): Argument #1 ($object) must be of type DateTimeZone, null given
offset:TypeError
timezone_offset_get(): Argument #1 ($object) must be of type DateTimeZone, null given
