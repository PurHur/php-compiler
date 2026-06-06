--TEST--
stdlib strcmp() — backed enum case TypeError (#7132, ext/standard/string.c, php-src-strict)
--FILE--
<?php
enum E: string { case A = 'x'; }
try {
    strcmp(E::A, 'x');
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
strcmp(): Argument #1 ($string1) must be of type string, E given
