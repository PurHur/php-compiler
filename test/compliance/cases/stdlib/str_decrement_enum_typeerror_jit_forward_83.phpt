--TEST--
stdlib str_decrement() JIT — backed enum case TypeError (#6233)
--ENV--
PHP_COMPILER_PROFILE=8.3
--FILE--
<?php
enum E: string { case A = 'y'; }
try {
    str_decrement(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
str_decrement(): Argument #1 ($string) must be of type string, E given
