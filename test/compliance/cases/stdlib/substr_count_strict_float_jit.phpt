--TEST--
stdlib substr_count() float offset/length under strict call site JIT (#13859, ext/standard/string.c)
--JIT--
--FILE--
<?php
declare(strict_types=1);
try {
    substr_count('abcabc', 'a', 1.9);
    echo "offset uncaught\n";
} catch (TypeError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    substr_count('abcabc', 'a', 0, 2.9);
    echo "length uncaught\n";
} catch (TypeError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: substr_count(): Argument #3 ($offset) must be of type int, float given
TypeError: substr_count(): Argument #4 ($length) must be of type ?int, float given
