--TEST--
stdlib str_getcsv() — null $separator TypeError (#16140, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);

try {
    str_getcsv('a,b', null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
str_getcsv(): Argument #2 ($separator) must be of type string, null given
