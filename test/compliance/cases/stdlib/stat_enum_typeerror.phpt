--TEST--
stdlib stat() — backed enum case TypeError (#6863, ext/standard/filestat.c)
--FILE--
<?php
enum E: string { case A = 'x'; }
try {
    stat(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
stat(): Argument #1 ($filename) must be of type string, E given
