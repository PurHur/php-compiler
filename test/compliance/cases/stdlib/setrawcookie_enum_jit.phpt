--TEST--
stdlib setrawcookie() JIT — backed enum case TypeError on name/value (#7413)
--FILE--
<?php
enum E: string { case A = 'v'; }
try {
    setrawcookie(E::A, 'cookie');
    echo "name uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
enum V: string { case B = 'x'; }
try {
    setrawcookie('n', V::B);
    echo "value uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
setrawcookie(): Argument #1 ($name) must be of type string, E given
setrawcookie(): Argument #2 ($value) must be of type string, V given
