--TEST--
stdlib substr_count() — null needle TypeError JIT (#18312, ext/standard/string.c)
--JIT--
--FILE--
<?php
try {
    substr_count('haystack', null);
    echo "null_needle: uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    substr_count('haystack', '');
    echo "empty_needle: uncaught\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
substr_count(): Argument #2 ($needle) must be of type string, null given
substr_count(): Argument #2 ($needle) cannot be empty
