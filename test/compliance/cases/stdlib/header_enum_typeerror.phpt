--TEST--
stdlib header() — backed enum case TypeError (#8834, ext/standard/head.c, php-src-strict)
--FILE--
<?php
enum E: string { case A = 'X-Test: 1'; }
try {
    header(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
header(): Argument #1 ($header) must be of type string, E given
