--TEST--
stdlib hash_equals() JIT — non-string operands TypeError (#4407, ext/hash/hash.c)
--FILE--
<?php
declare(strict_types=1);

try {
    hash_equals(1, 'a');
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    hash_equals('a', 2);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
TypeError: hash_equals(): Argument #1 ($known_string) must be of type string, int given
TypeError: hash_equals(): Argument #2 ($user_string) must be of type string, int given
