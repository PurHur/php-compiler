--TEST--
stdlib array_key_exists() null haystack TypeError (#27447, ext/standard/array.c)
--FILE--
<?php
try {
    var_export(array_key_exists('a', null));
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    var_export(key_exists('a', null));
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
TypeError:array_key_exists(): Argument #2 ($array) must be of type array, null given
TypeError:key_exists(): Argument #2 ($array) must be of type array, null given
