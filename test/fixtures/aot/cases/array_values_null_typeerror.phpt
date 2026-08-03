--TEST--
AOT: array_values(null) TypeError catchable (#27473, php-src ext/standard/array.c)
--FILE--
<?php
try {
    var_export(array_values(null));
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
$x = null;
try {
    var_export(array_values($x));
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    echo implode(',', array_values(['a' => 1, 'b' => 2])), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
TypeError:array_values(): Argument #1 ($array) must be of type array, null given
TypeError:array_values(): Argument #1 ($array) must be of type array, null given
1,2
