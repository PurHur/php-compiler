--TEST--
stdlib lcfirst() JIT — backed enum case TypeError (#6220)
--FILE--
<?php
enum E: string { case X = 'hello'; }
try {
    lcfirst(E::X);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
lcfirst(): Argument #1 ($string) must be of type string, E given
