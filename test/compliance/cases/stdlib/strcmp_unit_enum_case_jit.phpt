--TEST--
stdlib strcmp() JIT — unit enum case TypeError (#5665)
--FILE--
<?php
enum E { case A; }
try {
    strcmp(E::A, '');
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
strcmp(): Argument #1 ($string1) must be of type string, E given
