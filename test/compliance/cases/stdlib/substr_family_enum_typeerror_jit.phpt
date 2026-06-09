--TEST--
stdlib JIT substr_count/substr_replace — enum case operands TypeError (#5950)
--FILE--
<?php
enum Es: string { case B = 'hi'; }

try {
    substr_count(Es::B, 'h');
    echo "uncaught substr_count\n";
} catch (TypeError $e) {
    echo 'substr_count: ', $e->getMessage(), "\n";
}
try {
    substr_replace(Es::B, 'y', 0);
    echo "uncaught substr_replace\n";
} catch (TypeError $e) {
    echo 'substr_replace: ', $e->getMessage(), "\n";
}
--EXPECT--
substr_count: substr_count(): Argument #1 ($haystack) must be of type string, Es given
substr_replace: substr_replace(): Argument #1 ($string) must be of type array|string, Es given
