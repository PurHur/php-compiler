--TEST--
stdlib substr_count() null needle coerces to empty ValueError (#18347, ext/standard/string.c)
--FILE--
<?php
try {
    substr_count('abc', null);
    echo "null_needle: uncaught\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
try {
    substr_count('abc', '');
    echo "empty_needle: uncaught\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
substr_count(): Argument #2 ($needle) must not be empty
substr_count(): Argument #2 ($needle) must not be empty
