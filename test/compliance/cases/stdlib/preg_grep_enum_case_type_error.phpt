--TEST--
Stdlib: preg_grep() enum case array values throw Error (#5639, ext/standard/array.c)
--FILE--
<?php
enum E: string { case A = 'foo'; case B = 'bar'; }
try {
    preg_grep('/^f/', [E::A, E::B]);
    echo "no throw\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
Error: Object of class E could not be converted to string
