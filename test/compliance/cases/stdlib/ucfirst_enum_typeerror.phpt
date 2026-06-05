--TEST--
stdlib ucfirst() — backed enum case TypeError (#6220, ext/standard/string.c)
--FILE--
<?php
enum E: string { case X = 'hello'; }
try {
    ucfirst(E::X);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
ucfirst(): Argument #1 ($string) must be of type string, E given
