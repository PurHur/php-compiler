--TEST--
stdlib strlen() — TypeError when argument is null (#4365, ext/standard/basic_functions.c)
--FILE--
<?php
try {
    var_export(strlen(null));
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
echo strlen(''), "\n";
--EXPECT--
TypeError: strlen(): Argument #1 ($string) must be of type string, null given
0
