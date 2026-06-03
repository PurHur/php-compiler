--TEST--
stdlib constant() — TypeError for non-string name (#4846, ext/standard/basic_functions.c)
--FILE--
<?php
try {
    constant(1);
} catch (TypeError $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
try {
    constant(['A']);
} catch (TypeError $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
--EXPECT--
TypeError: constant(): Argument #1 ($name) must be of type string, int given
TypeError: constant(): Argument #1 ($name) must be of type string, array given
