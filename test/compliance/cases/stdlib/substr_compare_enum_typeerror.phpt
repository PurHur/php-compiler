--TEST--
stdlib substr_compare() — backed enum case TypeError (#6267, ext/standard/string.c)
--FILE--
<?php
enum E: string { case A = 'x'; }
try {
    substr_compare('a', E::A, 0);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
substr_compare(): Argument #2 ($needle) must be of type string, E given
