--TEST--
stdlib version_compare() — backed enum case TypeError (#5955, ext/standard/versioning.c)
--FILE--
<?php
enum E: string { case A = '1.0'; }
try {
    version_compare(E::A, '1.0');
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
version_compare(): Argument #1 ($version1) must be of type string, E given
