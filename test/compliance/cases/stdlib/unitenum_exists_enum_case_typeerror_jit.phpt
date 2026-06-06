--TEST--
stdlib unitenum_exists() JIT — backed enum case TypeError (#6884)
--FILE--
<?php
enum E: string { case A = 'a'; }
try {
    unitenum_exists(E::A);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
unitenum_exists(): Argument #1 ($enum) must be of type string, E given
