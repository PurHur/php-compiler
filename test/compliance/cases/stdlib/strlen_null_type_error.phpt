--TEST--
stdlib strlen() — null deprecated, returns 0 (PHP 8.2+, #5000, ext/standard/string.c)
--FILE--
<?php
try {
    echo strlen(null), "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
echo strlen(''), "\n";
--EXPECT--
strlen(): Argument #1 ($string) must be of type string, null given
0
