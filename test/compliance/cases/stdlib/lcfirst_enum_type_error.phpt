--TEST--
stdlib lcfirst() — backed enum case TypeError (#6003, ext/standard/string.c, php-src-strict)
--FILE--
<?php
enum E: string { case A = 'hello'; }
try {
    lcfirst(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
lcfirst(): Argument #1 ($string) must be of type string, E given
