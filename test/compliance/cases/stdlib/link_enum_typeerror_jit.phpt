--TEST--
stdlib link() JIT — backed enum case TypeError (#6267)
--FILE--
<?php
enum E: string { case A = 'x'; }
try {
    link(E::A, '/tmp/t');
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
link(): Argument #1 ($target) must be of type string, E given
