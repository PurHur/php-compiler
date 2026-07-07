--TEST--
stdlib date_create_from_format(null, …) under strict_types throws TypeError (#17052, ext/date/php_date.c)
--FILE--
<?php
declare(strict_types=1);

try {
    date_create_from_format(null, '2024-01-15');
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
date_create_from_format(): Argument #1 ($format) must be of type string, null given
