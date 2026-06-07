--TEST--
Stdlib: array_intersect() enum case array values throw Error (#5927, ext/standard/array.c)
--FILE--
<?php
enum E: string { case A = 'a'; case B = 'b'; }
try {
    array_intersect([E::A, E::B], [E::A]);
    echo "no throw\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
Error: Object of class E could not be converted to string
