--TEST--
JIT: array_keys(null) TypeError — Zend 8.2+ (#21915, ext/standard/array.c)
--JIT--
--FILE--
<?php
try {
    var_export(array_keys(null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: array_keys(): Argument #1 ($array) must be of type array, null given
