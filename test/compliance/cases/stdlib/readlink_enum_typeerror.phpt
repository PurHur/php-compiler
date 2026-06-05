--TEST--
stdlib readlink() — backed enum case TypeError (#6267, ext/standard/link.c)
--FILE--
<?php
enum E: string { case A = 'x'; }
try {
    readlink(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
readlink(): Argument #1 ($path) must be of type string, E given
