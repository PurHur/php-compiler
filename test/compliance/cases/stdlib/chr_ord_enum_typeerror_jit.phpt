--TEST--
stdlib chr()/ord() JIT — backed enum case TypeError (#5673, #5836)
--FILE--
<?php
enum E: int { case A = 65; }
try {
    chr(E::A);
    echo "chr uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    ord(E::A);
    echo "ord uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
chr(): Argument #1 ($codepoint) must be of type int, E given
ord(): Argument #1 ($character) must be of type string, E given
