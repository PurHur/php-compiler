--TEST--
stdlib linkinfo() — backed enum case TypeError (#6267, ext/standard/link.c)
--FILE--
<?php
enum E: string { case A = 'x'; }
try {
    linkinfo(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
linkinfo(): Argument #1 ($path) must be of type string, E given
