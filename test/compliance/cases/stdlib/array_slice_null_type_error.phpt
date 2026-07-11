--TEST--
stdlib array_slice() null array TypeError (php-src ext/standard/array.c, #12642)
--FILE--
<?php
try {
    array_slice(null, 0);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: array_slice(): Argument #1 ($array) must be of type array, null given
