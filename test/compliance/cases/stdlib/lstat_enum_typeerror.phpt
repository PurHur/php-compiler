--TEST--
stdlib lstat() — backed enum case TypeError (#6267, ext/standard/filestat.c)
--FILE--
<?php
enum E: string { case A = 'x'; }
try {
    lstat(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
lstat(): Argument #1 ($filename) must be of type string, E given
