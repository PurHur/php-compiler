--TEST--
stdlib tempnam() — enum case operands TypeError JIT (#6000, ext/standard/filestat.c)
--FILE--
<?php
enum E: string { case A = 'x'; }

try {
    tempnam(sys_get_temp_dir(), E::A);
    echo "prefix uncaught\n";
} catch (TypeError $e) {
    echo 'prefix: ', $e->getMessage(), "\n";
}

try {
    tempnam(E::A, 'p');
    echo "directory uncaught\n";
} catch (TypeError $e) {
    echo 'directory: ', $e->getMessage(), "\n";
}
--EXPECT--
prefix: tempnam(): Argument #2 ($prefix) must be of type string, E given
directory: tempnam(): Argument #1 ($directory) must be of type string, E given
