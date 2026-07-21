--TEST--
count(null)/sizeof(null) TypeError — Zend 8.2+ (#21914, ext/standard/array.c)
--FILE--
<?php
foreach (['count', 'sizeof'] as $f) {
    try {
        var_export($f(null));
        echo "\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}
--EXPECT--
TypeError: count(): Argument #1 ($value) must be of type Countable|array, null given
TypeError: sizeof(): Argument #1 ($value) must be of type Countable|array, null given
