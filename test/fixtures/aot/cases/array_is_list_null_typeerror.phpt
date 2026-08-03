--TEST--
AOT: array_is_list(null) TypeError catchable (#27474, php-src ext/standard/array.c)
--FILE--
<?php
try {
    var_export(array_is_list(null));
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
$x = null;
try {
    var_export(array_is_list($x));
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    var_export(array_is_list([1, 2, 3]));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
TypeError:array_is_list(): Argument #1 ($array) must be of type array, null given
TypeError:array_is_list(): Argument #1 ($array) must be of type array, null given
true
