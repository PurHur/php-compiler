--TEST--
stdlib memcmp() — unit enum case TypeError (#7118, ext/standard/string.c)
--FILE--
<?php
enum E { case A; }
try {
    memcmp(E::A, '', 0);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
memcmp(): Argument #1 ($string1) must be of type string, E given
