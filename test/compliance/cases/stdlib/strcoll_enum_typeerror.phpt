--TEST--
stdlib strcoll() — enum case TypeError (#4376, php-src-strict)
--FILE--
<?php
enum E: string { case A = 'x'; }
try {
    strcoll(E::A, 'a');
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
strcoll(): Argument #1 ($string1) must be of type string, E given
