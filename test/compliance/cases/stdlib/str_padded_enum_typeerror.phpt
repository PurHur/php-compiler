--TEST--
stdlib str_padded() — enum case TypeError (#7044, php-src-strict)
--FILE--
<?php
enum E: string { case A = 'x'; }

try {
    str_padded(E::A, 5);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
str_padded(): Argument #1 ($string) must be of type string, E given
