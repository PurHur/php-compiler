--TEST--
stdlib substr_compare() — TypeError on invalid types (#4565, ext/standard/string.c)
--FILE--
<?php
try {
    substr_compare([], 'a', 0);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    substr_compare('abc', [], 0);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    substr_compare('abc', 'ab', 'x', 2);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
substr_compare(): Argument #1 ($haystack) must be of type string, array given
substr_compare(): Argument #2 ($needle) must be of type string, array given
substr_compare(): Argument #3 ($offset) must be of type int, string given
