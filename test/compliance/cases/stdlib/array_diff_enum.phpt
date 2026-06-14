--TEST--
Stdlib: array_diff()/array_diff_assoc() enum case arrays throw Error (#5579, ext/standard/array.c)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }
try {
    var_export(array_diff([E::A], [E::B]));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export(array_diff_assoc([E::A], [E::B]));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
Error: Object of class E could not be converted to string
Error: Object of class E could not be converted to string
