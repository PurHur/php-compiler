--TEST--
stdlib str_getcsv() — backed enum case TypeError (#5884, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);

enum E: string { case A = 'a,b'; }
try {
    str_getcsv(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
str_getcsv(): Argument #1 ($string) must be of type string, E given
