--TEST--
stdlib str_getcsv() — null operand TypeError (#7442, ext/standard/file.c)
--FILE--
<?php
declare(strict_types=1);

try {
    str_getcsv(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
str_getcsv(): Argument #1 ($string) must be of type string, null given
