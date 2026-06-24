--TEST--
stdlib constant() — TypeError for non-string name (#4846, ext/standard/basic_functions.c)
--FILE--
<?php
try {
    constant(1);
} catch (Error $e) {
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
Error: Undefined constant "1"
TypeError: constant(): Argument #1 ($name) must be of type string, array given
