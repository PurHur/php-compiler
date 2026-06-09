--TEST--
stdlib crypt() — backed enum case salt TypeError (#5971, ext/standard/crypt.c)
--FILE--
<?php
enum E: string { case A = 'x'; }
try {
    crypt('pw', E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
crypt(): Argument #2 ($salt) must be of type string, E given
