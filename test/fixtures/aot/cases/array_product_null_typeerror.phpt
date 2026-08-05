--TEST--
AOT: array_product(null) TypeError catchable (#27483, php-src ext/standard/array.c)
--FILE--
<?php
try {
    echo array_product(null), " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
$x = null;
try {
    echo array_product($x), " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    echo array_product([2, 3, 4]), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
TypeError:array_product(): Argument #1 ($array) must be of type array, null given
TypeError:array_product(): Argument #1 ($array) must be of type array, null given
24
