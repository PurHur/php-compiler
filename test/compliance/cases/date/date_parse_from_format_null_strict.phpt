--TEST--
date_parse_from_format(null, …) / (…, null) under strict_types throws TypeError (#30308, ext/date/php_date.c)
--FILE--
<?php
declare(strict_types=1);
try {
    date_parse_from_format(null, 'Y');
    echo "format:fail\n";
} catch (Throwable $e) {
    echo 'format:', get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
try {
    date_parse_from_format('Y', null);
    echo "datetime:fail\n";
} catch (Throwable $e) {
    echo 'datetime:', get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
format:TypeError
date_parse_from_format(): Argument #1 ($format) must be of type string, null given
datetime:TypeError
date_parse_from_format(): Argument #2 ($datetime) must be of type string, null given
