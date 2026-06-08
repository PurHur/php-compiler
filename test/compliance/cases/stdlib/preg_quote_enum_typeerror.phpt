--TEST--
stdlib preg_quote() — enum case operand TypeError (#5999, ext/pcre/php_pcre.c)
--FILE--
<?php
declare(strict_types=1);
enum E: string { case A = 'x'; }
try {
    preg_quote(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
preg_quote(): Argument #1 ($str) must be of type string, E given
