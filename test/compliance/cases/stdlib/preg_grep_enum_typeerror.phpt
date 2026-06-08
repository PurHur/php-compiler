--TEST--
stdlib preg_grep() — enum case pattern TypeError (#5999, ext/pcre/php_pcre.c)
--FILE--
<?php
declare(strict_types=1);
enum E: string { case A = 'x'; }
try {
    preg_grep(E::A, []);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
preg_grep(): Argument #1 ($pattern) must be of type string, E given
