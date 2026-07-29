--TEST--
stdlib str_increment() JIT — backed enum case TypeError (#6233)
--ENV--
PHP_COMPILER_PROFILE=8.3
--FILE--
<?php
enum E: string { case A = 'x'; }
try {
    str_increment(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
str_increment(): Argument #1 ($string) must be of type string, E given
