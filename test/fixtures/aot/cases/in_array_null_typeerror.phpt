--TEST--
AOT: in_array(null haystack) TypeError catchable (issue #27448, php-src ext/standard/array.c)
--FILE--
<?php
try {
    var_export(in_array(1, null));
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
$x = null;
try {
    var_export(in_array(1, $x));
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    var_export(in_array('a', [1, 2], true));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
TypeError:in_array(): Argument #2 ($haystack) must be of type array, null given
TypeError:in_array(): Argument #2 ($haystack) must be of type array, null given
false
