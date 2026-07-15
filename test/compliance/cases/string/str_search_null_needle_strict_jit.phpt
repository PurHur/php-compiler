--TEST--
str_contains/str_starts_with/str_ends_with — null needle TypeError under strict_types JIT (#18344, ext/standard/string.c)
--JIT--
--FILE--
<?php
declare(strict_types=1);
foreach (['str_contains', 'str_starts_with', 'str_ends_with'] as $fn) {
    try {
        $fn('abc', null);
        echo $fn, ": uncaught\n";
    } catch (TypeError $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    }
}
--EXPECT--
str_contains: str_contains(): Argument #2 ($needle) must be of type string, null given
str_starts_with: str_starts_with(): Argument #2 ($needle) must be of type string, null given
str_ends_with: str_ends_with(): Argument #2 ($needle) must be of type string, null given
