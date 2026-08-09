--TEST--
stdlib substr_count() — null needle coerces to empty ValueError (#18347, ext/standard/string.c)
--JIT--
--FILE--
<?php
try {
    substr_count('haystack', null);
    echo "null_needle: uncaught\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
try {
    substr_count('haystack', '');
    echo "empty_needle: uncaught\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
substr_count(): Argument #2 ($needle) must not be empty
substr_count(): Argument #2 ($needle) must not be empty
