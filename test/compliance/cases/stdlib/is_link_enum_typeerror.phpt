--TEST--
stdlib is_link() — backed enum case TypeError (#6863, ext/standard/filestat.c)
--FILE--
<?php
enum E: string { case A = 'x'; }
try {
    is_link(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
is_link(): Argument #1 ($filename) must be of type string, E given
