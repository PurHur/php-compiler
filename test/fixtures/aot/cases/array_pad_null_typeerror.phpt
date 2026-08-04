--TEST--
AOT: array_pad(null) TypeError catchable (#27485, php-src ext/standard/array.c)
--FILE--
<?php
try {
    var_export(array_pad(null, 3, 'x'));
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
$a = null;
try {
    var_export(array_pad($a, 2, 0));
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    echo implode(',', array_pad([1], 3, 'x')), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
TypeError:array_pad(): Argument #1 ($array) must be of type array, null given
TypeError:array_pad(): Argument #1 ($array) must be of type array, null given
1,x,x
