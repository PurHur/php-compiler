--TEST--
stdlib strlen() JIT — unit enum case TypeError (#5119)
--FILE--
<?php
enum E { case A; }
try {
    strlen(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
strlen(): Argument #1 ($string) must be of type string, E given
