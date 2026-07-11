--TEST--
stdlib str_getcsv() — explicit null optional string args TypeError JIT (#16511, ext/standard/string.c)
--JIT--
--FILE--
<?php
declare(strict_types=1);

try {
    str_getcsv('a,b', null);
    echo "uncaught separator\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    str_getcsv('a,b', ',', null);
    echo "uncaught enclosure\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    str_getcsv('a,b', ',', '"', null);
    echo "uncaught escape\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
str_getcsv(): Argument #2 ($separator) must be of type string, null given
str_getcsv(): Argument #3 ($enclosure) must be of type string, null given
str_getcsv(): Argument #4 ($escape) must be of type string, null given
