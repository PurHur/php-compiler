--TEST--
AOT: count(null) TypeError catchable (issue #27446, php-src ext/standard/array.c)
--FILE--
<?php
try {
    var_export(count(null));
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
$x = null;
try {
    var_export(count($x));
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    var_export(sizeof(null));
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
TypeError:count(): Argument #1 ($value) must be of type Countable|array, null given
TypeError:count(): Argument #1 ($value) must be of type Countable|array, null given
TypeError:sizeof(): Argument #1 ($value) must be of type Countable|array, null given
