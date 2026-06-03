--TEST--
stdlib array_key_exists() — TypeError for non-array second argument (#4722, ext/standard/array.c)
--FILE--
<?php
class C
{
}
try {
    array_key_exists('key', new C());
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    array_key_exists(0, 'not-array');
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: array_key_exists(): Argument #2 ($array) must be of type array, C given
TypeError: array_key_exists(): Argument #2 ($array) must be of type array, string given
