--TEST--
date_offset_get(null) TypeError single Argument #1 prefix JIT (#29864, ext/date/php_date.c)
--FILE--
<?php
declare(strict_types=1);
try {
    date_offset_get(null);
    echo "fail\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
TypeError
date_offset_get(): Argument #1 ($object) must be of type DateTimeInterface, null given
