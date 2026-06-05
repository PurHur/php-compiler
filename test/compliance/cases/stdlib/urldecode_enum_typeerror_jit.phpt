--TEST--
stdlib urldecode()/rawurldecode() JIT — backed enum case TypeError (#6258)
--FILE--
<?php
enum E: string { case A = 'hello%20world'; }
try {
    urldecode(E::A);
    echo "urldecode uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    rawurldecode(E::A);
    echo "rawurldecode uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
urldecode(): Argument #1 ($string) must be of type string, E given
rawurldecode(): Argument #1 ($string) must be of type string, E given
