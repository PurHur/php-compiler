--TEST--
AOT: array_key_exists() null haystack TypeError is catchable (#27447)
--FILE--
<?php
try {
    var_export(array_key_exists('a', null));
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
TypeError:array_key_exists(): Argument #2 ($array) must be of type array, null given
